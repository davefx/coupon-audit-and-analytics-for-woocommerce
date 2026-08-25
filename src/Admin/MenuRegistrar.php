<?php
/**
 * Admin menu registration.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Admin;

/**
 * Puts the audit screen under the WooCommerce menu, which is where someone
 * looking for coupon information will already be.
 */
final class MenuRegistrar {

	/**
	 * The screen's page slug.
	 */
	public const PAGE_SLUG = 'dfxcaaw-inventory';

	/**
	 * Where WooCommerce keeps Coupons when it has not moved them.
	 */
	private const WOOCOMMERCE_SLUG = 'woocommerce';

	/**
	 * Constructor.
	 *
	 * @param InventoryPage $inventory The audit screen.
	 * @param MarginPage    $margins   The analytics screen.
	 * @param SettingsPage  $settings  The settings screen.
	 */
	public function __construct(
		private readonly InventoryPage $inventory,
		private readonly MarginPage $margins,
		private readonly SettingsPage $settings
	) {}

	/**
	 * The menu these screens belong under.
	 *
	 * Beside Coupons, wherever WooCommerce currently keeps them — and it does
	 * move them: they sit under Marketing when that feature is enabled, which it
	 * is by default, and under WooCommerce when it is not.
	 *
	 * Hardcoding either would put these screens in an empty menu on half of all
	 * shops, and leave them behind the next time Coupons move.
	 */
	public function parent_slug(): string {
		$coupons = get_post_type_object( 'shop_coupon' );

		// WooCommerce answers this itself: Internal\Admin\Coupons sets the
		// coupon post type's show_in_menu to woocommerce-marketing when the
		// marketing feature is on, and leaves it alone when it is not. Reading
		// that is better than repeating the condition, for the same reason
		// StatusResolver judges one rule rather than two — this cannot fall out
		// of step with WooCommerce, because it is asking WooCommerce.
		if ( null !== $coupons && is_string( $coupons->show_in_menu ) && '' !== $coupons->show_in_menu ) {
			return $coupons->show_in_menu;
		}

		return self::WOOCOMMERCE_SLUG;
	}

	/**
	 * Register the submenu page.
	 */
	public function register(): void {
		$parent = $this->parent_slug();

		add_submenu_page(
			$parent,
			__( 'Coupon Audit', 'coupon-audit-and-analytics-for-woocommerce' ),
			__( 'Coupon Audit', 'coupon-audit-and-analytics-for-woocommerce' ),
			InventoryPage::CAPABILITY,
			self::PAGE_SLUG,
			array( $this->inventory, 'render' )
		);

		add_submenu_page(
			$parent,
			__( 'Coupon Margin', 'coupon-audit-and-analytics-for-woocommerce' ),
			__( 'Coupon Margin', 'coupon-audit-and-analytics-for-woocommerce' ),
			InventoryPage::CAPABILITY,
			MarginPage::PAGE_SLUG,
			array( $this->margins, 'render' )
		);

		add_submenu_page(
			$parent,
			__( 'Coupon Audit Settings', 'coupon-audit-and-analytics-for-woocommerce' ),
			__( 'Coupon Audit Settings', 'coupon-audit-and-analytics-for-woocommerce' ),
			InventoryPage::CAPABILITY,
			SettingsPage::PAGE_SLUG,
			array( $this->settings, 'render' )
		);
	}
}
