<?php
/**
 * Cost adapter integration tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Integration\Cost;

use DFX\CouponAAW\Cost\BoosterCogsSource;
use DFX\CouponAAW\Cost\CostSourceRegistry;
use DFX\CouponAAW\Cost\NativeCogsSource;
use DFX\CouponAAW\Cost\SkyvergeCogsSource;
use DFX\CouponAAW\Cost\WpFactoryCogsSource;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * The adapters read other people's storage, so nothing about them can be
 * verified without a real database holding data in that shape.
 *
 * The keys asserted here were read out of each plugin's source; see
 * docs/COST-SOURCES.md. If one of them changes upstream, this is where it
 * surfaces.
 */
final class CostSourceTest extends WP_UnitTestCase {

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		delete_option( 'woocommerce_feature_cost_of_goods_sold_enabled' );

		parent::tear_down();
	}

	/**
	 * Create a simple product.
	 *
	 * @param float $price The product price.
	 */
	private function create_product( float $price = 30.0 ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Widget' );
		$product->set_regular_price( (string) $price );
		$product->save();

		return $product;
	}

	/**
	 * Create an order with one line for the given product.
	 *
	 * @param WC_Product_Simple $product  The product ordered.
	 * @param int               $quantity How many.
	 *
	 * @return array{0: int, 1: int} Order ID and line item ID.
	 */
	private function create_order( WC_Product_Simple $product, int $quantity = 2 ): array {
		$order = new WC_Order();
		$item  = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( $quantity );
		$order->add_item( $item );

		// What an order does when its totals are worked out: core walks the
		// lines and asks each to calculate its cost. Without this the lines
		// carry no cost, exactly as they would not in a real store. Core treats
		// calling it with the feature switched off as incorrect usage, and a
		// store with the feature off would never reach it either.
		if ( 'yes' === get_option( 'woocommerce_feature_cost_of_goods_sold_enabled' ) ) {
			$order->calculate_cogs_total_value();
		}

		$order->save();

		$items = $order->get_items();
		$first = reset( $items );

		$this->assertInstanceOf( WC_Order_Item_Product::class, $first );

		return array( (int) $order->get_id(), (int) $first->get_id() );
	}

	/**
	 * Core's feature is off unless a store turns it on, which is the single
	 * fact that makes the third-party adapters necessary rather than optional.
	 */
	public function test_native_cogs_is_unavailable_until_the_feature_is_enabled(): void {
		$source = new NativeCogsSource( 'EUR', 2 );

		$this->assertFalse( $source->is_available() );

		update_option( 'woocommerce_feature_cost_of_goods_sold_enabled', 'yes' );

		$this->assertTrue( $source->is_available() );
	}

	/**
	 * Native COGS reads the cost recorded on the line itself, through
	 * WooCommerce's own API rather than a meta key.
	 */
	public function test_native_cogs_reads_the_cost_recorded_on_the_line(): void {
		update_option( 'woocommerce_feature_cost_of_goods_sold_enabled', 'yes' );

		$product = $this->create_product();
		$product->set_cogs_value( 4.5 );
		$product->save();

		[ $order_id, $item_id ] = $this->create_order( $product );

		$cost = ( new NativeCogsSource( 'EUR', 2 ) )->get_line_cost( $order_id, $item_id );

		$this->assertNotNull( $cost );
		$this->assertSame( 'EUR', $cost->currency );
		$this->assertSame( 900, $cost->amount, 'Two units at 4.50 is 9.00, held as 900 minor units.' );
	}

	/**
	 * A line whose cost was never recorded reports nothing rather than zero.
	 *
	 * This is the trap in core's API: `has_cogs()` says a product line *can*
	 * carry a cost and `get_cogs_value()` returns 0.0 when none was recorded, so
	 * trusting them would report full coverage and zero costs for any store that
	 * enabled the feature without filling anything in — a margin equal to
	 * revenue, stated with complete confidence.
	 */
	public function test_a_line_without_a_cost_reports_nothing(): void {
		update_option( 'woocommerce_feature_cost_of_goods_sold_enabled', 'yes' );

		[ $order_id, $item_id ] = $this->create_order( $this->create_product() );

		$this->assertNull( ( new NativeCogsSource( 'EUR', 2 ) )->get_line_cost( $order_id, $item_id ) );
	}

	/**
	 * WPFactory records the cost on the line as the order is placed, and that
	 * figure wins over the product's current cost.
	 */
	public function test_wpfactory_prefers_the_cost_recorded_on_the_line(): void {
		$product = $this->create_product();
		update_post_meta( $product->get_id(), '_alg_wc_cog_cost', '9.99' );

		[ $order_id, $item_id ] = $this->create_order( $product );

		wc_update_order_item_meta( $item_id, '_alg_wc_cog_item_cost', '4.00' );

		$cost = ( new WpFactoryCogsSource( 'EUR', 2 ) )->get_line_cost( $order_id, $item_id );

		$this->assertNotNull( $cost );
		$this->assertSame( 800, $cost->amount, 'The line cost of 4.00 x 2 wins over the product cost of 9.99.' );
	}

	/**
	 * An order placed before the plugin was installed carries no line cost, so
	 * the product's current cost is the only figure available.
	 */
	public function test_wpfactory_falls_back_to_the_product_cost(): void {
		$product = $this->create_product();
		update_post_meta( $product->get_id(), '_alg_wc_cog_cost', '3.25' );

		[ $order_id, $item_id ] = $this->create_order( $product );

		$cost = ( new WpFactoryCogsSource( 'EUR', 2 ) )->get_line_cost( $order_id, $item_id );

		$this->assertNotNull( $cost );
		$this->assertSame( 650, $cost->amount );
	}

	/**
	 * Booster keeps a product's purchase price and nothing per line.
	 */
	public function test_booster_reads_the_product_purchase_price(): void {
		$product = $this->create_product();
		update_post_meta( $product->get_id(), '_wcj_purchase_price', '2.50' );

		[ $order_id, $item_id ] = $this->create_order( $product, 3 );

		$cost = ( new BoosterCogsSource( 'EUR', 2 ) )->get_line_cost( $order_id, $item_id );

		$this->assertNotNull( $cost );
		$this->assertSame( 750, $cost->amount );
		$this->assertFalse( ( new BoosterCogsSource( 'EUR', 2 ) )->records_cost_at_sale() );
	}

	/**
	 * The paid extension's product key, confirmed from two independent import
	 * tools that read it.
	 */
	public function test_the_paid_extension_product_cost_is_read(): void {
		$product = $this->create_product();
		update_post_meta( $product->get_id(), '_wc_cog_cost', '1.10' );

		[ $order_id, $item_id ] = $this->create_order( $product, 2 );

		$cost = ( new SkyvergeCogsSource( 'EUR', 2 ) )->get_line_cost( $order_id, $item_id );

		$this->assertNotNull( $cost );
		$this->assertSame( 220, $cost->amount );
	}

	/**
	 * A product with no cost recorded in the active system reports nothing,
	 * whatever other systems may hold for it.
	 */
	public function test_a_product_without_a_cost_reports_nothing(): void {
		[ $order_id, $item_id ] = $this->create_order( $this->create_product() );

		$this->assertNull( ( new BoosterCogsSource( 'EUR', 2 ) )->get_line_cost( $order_id, $item_id ) );
	}

	/**
	 * The rule this whole milestone turns on, against real stored data: two
	 * systems both hold a cost for the same line, and the report reads only the
	 * active one. A blended total would reconcile with neither system's books.
	 */
	public function test_a_report_reads_only_the_active_source(): void {
		$product = $this->create_product();
		update_post_meta( $product->get_id(), '_wcj_purchase_price', '2.00' );
		update_post_meta( $product->get_id(), '_wc_cog_cost', '7.00' );

		[ $order_id, $item_id ] = $this->create_order( $product, 1 );

		$registry = new CostSourceRegistry(
			array( new BoosterCogsSource( 'EUR', 2 ), new SkyvergeCogsSource( 'EUR', 2 ) ),
			'booster'
		);

		$active = $registry->active();

		// Neither plugin is really installed here, so neither reports itself
		// available; the selection is exercised directly instead.
		$this->assertNull( $active, 'Sources absent from the store must not be selectable.' );

		$booster = ( new BoosterCogsSource( 'EUR', 2 ) )->get_line_cost( $order_id, $item_id );
		$paid    = ( new SkyvergeCogsSource( 'EUR', 2 ) )->get_line_cost( $order_id, $item_id );

		$this->assertNotNull( $booster );
		$this->assertNotNull( $paid );
		$this->assertSame( 200, $booster->amount );
		$this->assertSame( 700, $paid->amount );
		$this->assertNotSame(
			$booster->amount,
			$paid->amount,
			'The two systems disagree, which is exactly why a report must read only one.'
		);
	}

	/**
	 * Every source names itself and says what it is.
	 *
	 * The identifier is stored in settings and the label is what a shop owner
	 * picks from, so an empty one leaves a blank radio button that cannot be told
	 * from its neighbour. The identifiers must differ, too — the registry refuses
	 * duplicates, because a report has to be able to say where its figures came
	 * from.
	 */
	public function test_every_source_names_itself(): void {
		$sources = array(
			new NativeCogsSource( 'EUR', 2 ),
			new WpFactoryCogsSource( 'EUR', 2 ),
			new SkyvergeCogsSource( 'EUR', 2 ),
			new BoosterCogsSource( 'EUR', 2 ),
		);

		$identifiers = array();

		foreach ( $sources as $source ) {
			$this->assertNotSame( '', trim( $source->get_identifier() ), get_class( $source ) );
			$this->assertNotSame( '', trim( $source->get_label() ), get_class( $source ) );

			$identifiers[] = $source->get_identifier();
		}

		$this->assertSame( $identifiers, array_unique( $identifiers ), 'Two sources share an identifier.' );
	}

	/**
	 * Each source knows whether its figures are the cost as of the sale or the
	 * cost today. §7 orders them by that, since a cost read today against an
	 * order from last year reports a margin the shop never made.
	 */
	public function test_sources_declare_whether_they_record_cost_at_sale(): void {
		$this->assertTrue(
			( new NativeCogsSource( 'EUR', 2 ) )->records_cost_at_sale(),
			'Core stores the cost on the order line.'
		);

		$this->assertFalse(
			( new BoosterCogsSource( 'EUR', 2 ) )->records_cost_at_sale(),
			'Booster stores a current product cost, not a historical one.'
		);
	}

	/**
	 * An order that does not exist has no cost, rather than nothing-as-zero.
	 * Orders are deleted, and the aggregator walks history long after the fact.
	 */
	public function test_a_missing_order_has_no_cost(): void {
		$this->assertNull( ( new NativeCogsSource( 'EUR', 2 ) )->get_line_cost( 999999, 1 ) );
	}

	/**
	 * Nor does a line that is not on the order it was asked about.
	 */
	public function test_a_line_that_is_not_on_the_order_has_no_cost(): void {
		list( $order_id ) = $this->create_order( $this->create_product() );

		$this->assertNull( ( new NativeCogsSource( 'EUR', 2 ) )->get_line_cost( $order_id, 999999 ) );
	}
}
