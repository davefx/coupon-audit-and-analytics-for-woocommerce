<?php
/**
 * Booster for WooCommerce.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Cost;

/**
 * Booster's purchase-price field, from its Product Cost module.
 *
 * The widest-installed of the systems surveyed, and product-level only: Booster
 * records what a product costs, never what a line cost when it sold.
 */
final class BoosterCogsSource extends ProductMetaCostSource {

	/**
	 * Whether Booster is active.
	 */
	protected function plugin_is_present(): bool {
		return defined( 'WCJ_VERSION' ) || function_exists( 'wcj_get_product_purchase_price' );
	}

	/**
	 * Stable machine name.
	 */
	public function get_identifier(): string {
		return 'booster';
	}

	/**
	 * Human-readable name.
	 */
	public function get_label(): string {
		return __( 'Booster for WooCommerce', 'coupon-audit-and-analytics-for-woocommerce' );
	}

	/**
	 * Below the systems that record cost as of the sale.
	 */
	public function get_priority(): int {
		return 40;
	}

	/**
	 * Where Booster keeps a product's cost.
	 */
	protected function cost_meta_key(): string {
		return '_wcj_purchase_price';
	}
}
