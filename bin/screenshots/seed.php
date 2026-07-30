<?php
/**
 * Seed a demo shop: enough coupons to show every finding, and enough orders to
 * show all three cost-coverage states.
 */

function demo_product( string $name, string $price, ?float $cost ): WC_Product_Simple {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( $price );
	$p->set_catalog_visibility( 'visible' );
	if ( null !== $cost ) {
		$p->set_cogs_value( $cost );
	}
	$p->save();
	return $p;
}

function demo_coupon( string $code, array $args = array() ): WC_Coupon {
	$c = new WC_Coupon();
	$c->set_code( $code );
	$c->set_discount_type( $args['type'] ?? 'percent' );
	$c->set_amount( (string) ( $args['amount'] ?? 10 ) );
	if ( isset( $args['expires'] ) ) {
		$c->set_date_expires( $args['expires'] );
	}
	if ( isset( $args['limit'] ) ) {
		$c->set_usage_limit( $args['limit'] );
	}
	if ( isset( $args['products'] ) ) {
		$c->set_product_ids( $args['products'] );
	}
	$id = $c->save();

	if ( isset( $args['created'] ) ) {
		wp_update_post( array( 'ID' => $id, 'post_date' => $args['created'], 'post_date_gmt' => get_gmt_from_date( $args['created'] ) ) );
	}
	return new WC_Coupon( $id );
}

function demo_order( array $lines, WC_Coupon $coupon, string $when ): void {
	$order = new WC_Order();
	foreach ( $lines as [ $product, $qty ] ) {
		$order->add_product( $product, $qty );
	}
	$order->set_date_created( $when );
	$order->save();
	$order->apply_coupon( $coupon );
	$order->calculate_totals();
	$order->calculate_cogs_total_value();
	$order->set_status( 'completed' );
	$order->set_date_created( $when );
	$order->save();

	\Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore::sync_order( $order->get_id() );
	\Automattic\WooCommerce\Admin\API\Reports\Coupons\DataStore::sync_order_coupons( $order->get_id() );
}

$beans = demo_product( 'Single-Origin Espresso, 1kg', '24.00', 8.40 );
$mug   = demo_product( 'Stoneware Mug', '12.00', 3.50 );
$card  = demo_product( 'Gift Card', '25.00', null );   // no cost recorded, on purpose
$grind = demo_product( 'Hand Grinder', '68.00', 31.00 );

$now = new DateTimeImmutable( 'now', wp_timezone() );
$ago = static fn( int $d ): string => $now->modify( "-{$d} days" )->format( 'Y-m-d H:i:s' );

// Healthy, restricted, well-behaved.
$launch = demo_coupon( 'grinder15', array( 'amount' => 15, 'expires' => $now->modify('+60 days')->format('Y-m-d'), 'limit' => 200, 'products' => array( $grind->get_id() ) ) );

// Live, unrestricted, no expiry, no cap — overlaps everything.
$welcome = demo_coupon( 'welcome10', array( 'amount' => 10 ) );

// A campaign whose other codes have expired.
$summer_a = demo_coupon( 'summer24-a', array( 'amount' => 20, 'expires' => $now->modify('+30 days')->format('Y-m-d'), 'limit' => 500 ) );
demo_coupon( 'summer24-b', array( 'amount' => 20, 'expires' => $now->modify('-20 days')->format('Y-m-d'), 'limit' => 500, 'created' => $ago( 120 ) ) );
demo_coupon( 'summer24-c', array( 'amount' => 20, 'expires' => $now->modify('-15 days')->format('Y-m-d'), 'limit' => 500, 'created' => $ago( 120 ) ) );

// Dormant: old, never redeemed.
demo_coupon( 'vip-earlyaccess', array( 'amount' => 25, 'expires' => $now->modify('+90 days')->format('Y-m-d'), 'limit' => 50, 'created' => $ago( 240 ) ) );

// Exhausted, and one plainly expired.
$spent = demo_coupon( 'flash50', array( 'amount' => 50, 'expires' => $now->modify('+10 days')->format('Y-m-d'), 'limit' => 25, 'created' => $ago( 30 ) ) );
$spent->set_usage_count( 25 );
$spent->save();

demo_coupon( 'blackfriday23', array( 'amount' => 30, 'expires' => $now->modify('-200 days')->format('Y-m-d'), 'limit' => 1000, 'created' => $ago( 400 ) ) );

// Orders: full coverage, partial coverage, and none.
for ( $i = 1; $i <= 6; $i++ ) {
	demo_order( array( array( $beans, 2 ), array( $mug, 1 ) ), $welcome, $ago( $i ) );
}
for ( $i = 2; $i <= 5; $i++ ) {
	demo_order( array( array( $beans, 1 ), array( $card, 1 ) ), $summer_a, $ago( $i ) );
}
for ( $i = 1; $i <= 3; $i++ ) {
	demo_order( array( array( $card, 2 ) ), $launch, $ago( $i ) );
}
for ( $i = 1; $i <= 4; $i++ ) {
	demo_order( array( array( $grind, 1 ) ), $launch, $ago( $i ) );
}

// Aggregate the window the screen shows.
$aggregation = \DFX\CouponAAW\Plugin::get_instance()->container()->get( \DFX\CouponAAW\Service\AggregationInterface::class );
for ( $d = 0; $d <= 10; $d++ ) {
	$aggregation->aggregate_day( $now->modify( "-{$d} days" )->setTime( 0, 0 ) );
}

echo "seeded\n";
