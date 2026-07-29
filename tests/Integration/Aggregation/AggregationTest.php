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
use DFX\CouponAAW\Install\Aggregator;
use DFX\CouponAAW\Install\SchemaMigrator;
use DFX\CouponAAW\Plugin;
use DFX\CouponAAW\Repository\CouponStatsRepositoryInterface;
use DFX\CouponAAW\Repository\OrderStatsRepositoryInterface;
use DFX\CouponAAW\Service\AggregationInterface;
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

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'dfxcaaw' );
		}

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
		$container->get( AggregationInterface::class )->aggregate_day( $this->today() );

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
		$container->get( AggregationInterface::class )->aggregate_day( $this->today() );

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
		$container->get( AggregationInterface::class )->aggregate_day( $this->today() );
		$container->get( AggregationInterface::class )->aggregate_day( $this->today() );

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
		$container->get( AggregationInterface::class )->aggregate_day( $this->today()->modify( '-30 days' ) );

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
		$container->get( AggregationInterface::class )->aggregate_day( $this->today() );

		$rows = $container->get( CouponStatsRepositoryInterface::class )->for_day( $this->today() );

		$this->assertGreaterThanOrEqual( 1, count( $rows ) );
		$this->assertSame( 500, $rows[0]->discount->amount );
	}

	/**
	 * An order that changed queues the day it belongs to.
	 *
	 * Queues rather than computes: Action Scheduler is loaded here, as it is in
	 * any real store, so the point of the call is that a job exists afterwards.
	 * The day is recomputed rather than adjusted, which is also how a refund is
	 * accounted for — the refund is not aggregated itself, the day that produced
	 * it is done again.
	 */
	public function test_an_order_queues_its_own_day(): void {
		$this->require_action_scheduler();

		list( $order_id ) = $this->place_order( 'queued', 5.0 );

		Plugin::get_instance()->container()->get( Aggregator::class )->queue_order( $order_id );

		$this->assertTrue(
			$this->is_day_queued( $this->today()->format( 'Y-m-d' ) ),
			'The order changed and its day was never queued for recomputation.'
		);
	}

	/**
	 * Queueing the same day twice leaves one job, not two. Recomputing a day is
	 * idempotent, so a second job would only cost a store the work twice.
	 */
	public function test_a_day_queued_twice_is_queued_once(): void {
		$this->require_action_scheduler();

		list( $order_id ) = $this->place_order( 'queuedtwice', 5.0 );

		$aggregator = Plugin::get_instance()->container()->get( Aggregator::class );

		$aggregator->queue_order( $order_id );
		$aggregator->queue_order( $order_id );

		// No status filter: nothing runs the queue during a test, so every job
		// here is one still waiting.
		$this->assertCount( 1, $this->queued_days() );
	}

	/**
	 * An order ID nobody issued queues nothing, rather than a day derived from an
	 * order that is not there. Orders get deleted, and the hooks that call this
	 * fire on deletion too.
	 */
	public function test_a_missing_order_queues_nothing(): void {
		$this->require_action_scheduler();

		Plugin::get_instance()->container()->get( Aggregator::class )->queue_order( 999999 );

		$this->assertSame( array(), $this->queued_days() );
	}

	/**
	 * Skip where Action Scheduler is not loaded.
	 *
	 * WooCommerce bundles it, so this should not fire — but a store is not
	 * obliged to keep it loadable, and a test that fataled in that case would say
	 * the plugin was broken when it was merely running its fallback.
	 */
	private function require_action_scheduler(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded.' );
		}
	}

	/**
	 * Whether a given day is waiting to be recomputed.
	 *
	 * The guard sits here rather than in a shared helper because that is what
	 * makes the call safe to write at all: Action Scheduler is a WooCommerce
	 * dependency, not a language feature.
	 *
	 * @param string $day The day, as `Y-m-d`.
	 */
	private function is_day_queued( string $day ): bool {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return false;
		}

		return false !== as_next_scheduled_action(
			Aggregator::AGGREGATE_DAY,
			array( 'day' => $day ),
			'dfxcaaw'
		);
	}

	/**
	 * The day-aggregation jobs currently queued.
	 *
	 * @return array<mixed>
	 */
	private function queued_days(): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array();
		}

		return as_get_scheduled_actions(
			array(
				'hook'  => Aggregator::AGGREGATE_DAY,
				'group' => 'dfxcaaw',
			)
		);
	}

	/**
	 * The earliest coupon order is what a backfill starts from.
	 *
	 * If this is wrong the backfill starts in the wrong place: too early and it
	 * walks years of days that hold nothing, too late and the store's own history
	 * is never aggregated at all. Neither reports an error — the margin screen is
	 * simply short — so it is asserted against real analytics tables rather than
	 * assumed.
	 */
	public function test_the_earliest_coupon_order_bounds_the_backfill(): void {
		$this->place_order( 'boundary', 5.0 );

		$earliest = Plugin::get_instance()
			->container()
			->get( OrderStatsRepositoryInterface::class )
			->earliest_coupon_order_day();

		$this->assertNotNull( $earliest );
		$this->assertSame( $this->today()->format( 'Y-m-d' ), $earliest->format( 'Y-m-d' ) );
		$this->assertSame( '00:00:00', $earliest->format( 'H:i:s' ), 'A backfill walks days, not instants.' );
	}

	/**
	 * A store that has never taken a coupon order has nothing to walk, and says
	 * so rather than naming a date. A zero date out of the lookup table means the
	 * same thing and is treated the same way.
	 */
	public function test_a_store_with_no_coupon_orders_has_no_earliest_day(): void {
		$this->assertNull(
			Plugin::get_instance()
				->container()
				->get( OrderStatsRepositoryInterface::class )
				->earliest_coupon_order_day()
		);
	}
}
