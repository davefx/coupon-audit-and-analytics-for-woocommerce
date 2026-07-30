<?php
$now  = new DateTimeImmutable( 'now', wp_timezone() );
$card = null;

foreach ( wc_get_products( array( 'limit' => -1 ) ) as $p ) {
	if ( 'Gift Card' === $p->get_name() ) {
		$card = $p;
	}
}

// Unrestricted, so it applies to a basket of gift cards — which carry no cost,
// giving the third coverage state the screen must handle.
$c = new WC_Coupon();
$c->set_code( 'giftcard5' );
$c->set_discount_type( 'fixed_cart' );
$c->set_amount( '5' );
$c->set_date_expires( $now->modify( '+45 days' )->format( 'Y-m-d' ) );
$c->set_usage_limit( 100 );
$c->save();
$coupon = new WC_Coupon( $c->get_id() );

for ( $i = 1; $i <= 5; $i++ ) {
	$order = new WC_Order();
	$order->add_product( $card, 2 );
	$order->set_date_created( $now->modify( "-{$i} days" )->format( 'Y-m-d H:i:s' ) );
	$order->save();
	$order->apply_coupon( $coupon );
	$order->calculate_totals();
	$order->calculate_cogs_total_value();
	$order->set_status( 'completed' );
	$order->set_date_created( $now->modify( "-{$i} days" )->format( 'Y-m-d H:i:s' ) );
	$order->save();

	\Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore::sync_order( $order->get_id() );
	\Automattic\WooCommerce\Admin\API\Reports\Coupons\DataStore::sync_order_coupons( $order->get_id() );
}

$aggregation = \DFX\CouponAAW\Plugin::get_instance()->container()->get( \DFX\CouponAAW\Service\AggregationInterface::class );
for ( $d = 0; $d <= 10; $d++ ) {
	$aggregation->aggregate_day( $now->modify( "-{$d} days" )->setTime( 0, 0 ) );
}
echo "seeded\n";
