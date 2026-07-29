<?php
/**
 * Cost of Goods for WooCommerce, by WPFactory.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Cost;

use DFX\CouponAAW\Domain\Profit\Money;
use WC_Abstract_Order;
use WC_Order_Item;

/**
 * The most capable of the third-party systems, and the only one besides core
 * that records what a line cost at the moment it was sold.
 *
 * It writes `_alg_wc_cog_item_cost` onto the order line as the order is placed,
 * and keeps `_alg_wc_cog_cost` as the product's current cost. The line figure is
 * preferred wherever it exists; the product figure is the fallback for orders
 * predating the plugin's installation, and only then is the answer an estimate.
 */
final class WpFactoryCogsSource extends ProductMetaCostSource {

	/**
	 * Where the plugin keeps a line's cost as of the sale.
	 */
	private const LINE_COST_META = '_alg_wc_cog_item_cost';

	/**
	 * Whether the plugin is active.
	 */
	public function is_available(): bool {
		return defined( 'ALG_WC_COST_OF_GOODS_VERSION' )
			|| function_exists( 'alg_wc_cog' )
			|| function_exists( 'wpfcogs' );
	}

	/**
	 * Stable machine name.
	 */
	public function get_identifier(): string {
		return 'wpfactory-cog';
	}

	/**
	 * Human-readable name.
	 */
	public function get_label(): string {
		return __( 'Cost of Goods for WooCommerce (WPFactory)', 'coupon-audit-and-analytics-for-woocommerce' );
	}

	/**
	 * What one order line cost.
	 *
	 * The line's own recorded cost wins. Only when the order predates the
	 * plugin, and so carries no line cost, does this fall back to the product's
	 * current cost.
	 *
	 * @param int $order_id     The order.
	 * @param int $line_item_id The line within it.
	 */
	public function get_line_cost( int $order_id, int $line_item_id ): ?Money {
		$order = wc_get_order( $order_id );

		if ( $order instanceof WC_Abstract_Order ) {
			$item = $order->get_item( $line_item_id );

			if ( $item instanceof WC_Order_Item ) {
				$stored = $item->get_meta( self::LINE_COST_META, true );

				if ( is_numeric( $stored ) ) {
					return Money::from_decimal( (float) $stored, $this->currency, $this->decimals )
						->times( max( 1, (int) $item->get_quantity() ) );
				}
			}
		}

		return parent::get_line_cost( $order_id, $line_item_id );
	}

	/**
	 * Preferred over the product-only systems, below core's own.
	 */
	public function get_priority(): int {
		return 20;
	}

	/**
	 * Where a line cost was recorded it is the cost as of the sale.
	 */
	public function records_cost_at_sale(): bool {
		return true;
	}

	/**
	 * Where the plugin keeps a product's current cost.
	 */
	protected function cost_meta_key(): string {
		return '_alg_wc_cog_cost';
	}
}
