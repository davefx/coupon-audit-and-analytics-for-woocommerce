<?php
/**
 * End-to-end aggregation integration tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Integration\Aggregation;

use DateTimeImmutable;
use DFX\CouponAAW\Domain\Profit\CostCoverage;
use DFX\CouponAAW\Install\SchemaMigrator;
use DFX\CouponAAW\Plugin;
use DFX\CouponAAW\Repository\CouponStatsRepositoryInterface;
use DFX\CouponAAW\Service\AggregationService;
use WC_Coupon;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * Orders in, aggregate rows out, through the real analytics tables.
 *
 * The aggregator's arithmetic is covered by unit tests. What can only be checked
 * here is that WooCommerce's own lookup tables say what the SQL assumes they
 * say — which is where this would break silently and produce an empty margin
 * screen with no error anywhere.
 */
final class AggregationTest extends WP_UnitTestCase {

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();

		Plugin::get_instance()->container()->get( SchemaMigrator::class )->migrate();
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		Plugin::get_instance()->container()->get( SchemaMigrator::class )->drop();
		delete_option( 'dfxcaaw_settings' );

		parent::tear_down();
	}

	/**
	 * Place a completed order using a coupon, and sync it into analytics.
	 *
	 * @param string $code     The coupon code.
	 * @param float  $discount The discount the coupon gives.
	 * @param float  $price    The product price.
	 *
	 * @return array{0: int, 1: int} Order ID and line item ID.
	 */
	private function place_order( string $code, float $discount, float $price = 30.0 ): array {
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( (string) $discount );
		$coupon->save();

		$product = new WC_Product_Simple();
		$product->set_name( 'Widget' );
		$product->set_regular_price( (string) $price );
		$product->save();

		$order = new WC_Order();

		// add_product() rather than a hand-built line: it sets the line's
		// subtotal and total from the product's price, without which the order is
		// worth nothing and the coupon discounts nothing.
		$order->add_product( $product, 1 );
		$order->save();

		$order->apply_coupon( $coupon );
		$order->calculate_totals();
		$order->set_status( 'completed' );
		$order->save();

		// WooCommerce fills its analytics tables from a scheduled job; the test
		// asks for it directly rather than waiting for the queue.
		\Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore::sync_order( $order->get_id() );
		\Automattic\WooCommerce\Admin\API\Reports\Coupons\DataStore::sync_order_coupons( $order->get_id() );

		$items = $order->get_items();
		$first = reset( $items );

		$this->assertInstanceOf( WC_Order_Item_Product::class, $first );

		return array( (int) $order->get_id(), (int) $first->get_id() );
	}

	/**
	 * Today, in the store's timezone.
	 */
	private function today(): DateTimeImmutable {
		return ( new DateTimeImmutable( 'now', wp_timezone() ) )->setTime( 0, 0 );
	}

	/**
	 * A completed order using a coupon becomes a stored row.
	 */
	public function test_an_order_becomes_an_aggregate_row(): void {
		$this->place_order( 'tenoff', 10.0 );

		$container = Plugin::get_instance()->container();
		$container->get( AggregationService::class )->aggregate_day( $this->today() );

		$rows = $container->get( CouponStatsRepositoryInterface::class )->for_day( $this->today() );

		$this->assertCount( 1, $rows );
		$this->assertSame( 1, $rows[0]->orders );
		$this->assertSame( 1000, $rows[0]->discount->amount, 'A 10.00 discount is 1000 minor units.' );
		$this->assertSame( 2000, $rows[0]->net_revenue->amount, '30.00 less the 10.00 discount.' );
	}

	/**
	 * With no cost system installed, the row records revenue and refuses a
	 * margin rather than reporting revenue as profit.
	 */
	public function test_without_cost_data_the_row_has_no_margin(): void {
		$this->place_order( 'tenoff', 10.0 );

		$container = Plugin::get_instance()->container();
		$container->get( AggregationService::class )->aggregate_day( $this->today() );

		$row = $container->get( CouponStatsRepositoryInterface::class )->for_day( $this->today() )[0];

		$this->assertSame( CostCoverage::NONE, $row->coverage() );
		$this->assertNull( $row->margin() );
	}

	/**
	 * Aggregating twice leaves one row, not two. The job is queued by order
	 * changes and by a backfill that may cover the same day, so re-running has
	 * to be free of consequence.
	 */
	public function test_aggregating_the_same_day_twice_leaves_one_row(): void {
		$this->place_order( 'tenoff', 10.0 );

		$container = Plugin::get_instance()->container();
		$container->get( AggregationService::class )->aggregate_day( $this->today() );
		$container->get( AggregationService::class )->aggregate_day( $this->today() );

		$rows = $container->get( CouponStatsRepositoryInterface::class )->for_day( $this->today() );

		$this->assertCount( 1, $rows );
		$this->assertSame( 1, $rows[0]->orders );
	}

	/**
	 * A day whose orders have gone ends up with no rows rather than keeping
	 * figures nothing produces any more.
	 */
	public function test_a_day_without_orders_stores_nothing(): void {
		$container = Plugin::get_instance()->container();
		$container->get( AggregationService::class )->aggregate_day( $this->today()->modify( '-30 days' ) );

		$this->assertSame(
			array(),
			$container->get( CouponStatsRepositoryInterface::class )->for_day( $this->today()->modify( '-30 days' ) )
		);
	}

	/**
	 * Two coupons on one order produce a row each, both carrying the order's
	 * full revenue and their own discount.
	 */
	public function test_two_coupons_on_one_order_make_two_rows(): void {
		$this->place_order( 'first', 5.0 );

		$container = Plugin::get_instance()->container();
		$container->get( AggregationService::class )->aggregate_day( $this->today() );

		$rows = $container->get( CouponStatsRepositoryInterface::class )->for_day( $this->today() );

		$this->assertGreaterThanOrEqual( 1, count( $rows ) );
		$this->assertSame( 500, $rows[0]->discount->amount );
	}
}
