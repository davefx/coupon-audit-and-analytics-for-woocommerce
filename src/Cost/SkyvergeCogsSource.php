<?php
/**
 * WooCommerce Cost of Goods, the paid extension.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Cost;

/**
 * The long-standing paid extension sold through WooCommerce.com.
 *
 * Its product key `_wc_cog_cost` is confirmed from two independent sources that
 * read it in order to import from it: WPFactory's import tool defaults to it,
 * and Booster's import screen, labelled "WooCommerce Cost of Goods (source)",
 * reads it.
 *
 * Its per-line key is deliberately not implemented. The extension's source is
 * not publicly available, and the one candidate that appeared during the survey
 * turned out to be a substring artefact of WPFactory's own key rather than a
 * real reference. Reading a key that does not exist would report a confident,
 * wrong margin, which §6.3 is explicit is worse than reporting nothing — so
 * this reads product cost only until the key can be verified against the
 * extension itself.
 */
final class SkyvergeCogsSource extends ProductMetaCostSource {

	/**
	 * Whether the extension is active.
	 */
	protected function plugin_is_present(): bool {
		return class_exists( 'WC_COG' ) || defined( 'WC_COG_VERSION' );
	}

	/**
	 * Stable machine name.
	 */
	public function get_identifier(): string {
		return 'woocommerce-cog';
	}

	/**
	 * Human-readable name.
	 */
	public function get_label(): string {
		return __( 'WooCommerce Cost of Goods', 'coupon-audit-and-analytics-for-woocommerce' );
	}

	/**
	 * Below the systems whose per-line figures are verified.
	 */
	public function get_priority(): int {
		return 30;
	}

	/**
	 * Where the extension keeps a product's cost.
	 */
	protected function cost_meta_key(): string {
		return '_wc_cog_cost';
	}
}
