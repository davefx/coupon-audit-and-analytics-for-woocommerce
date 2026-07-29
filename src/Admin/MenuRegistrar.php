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
	 * The WooCommerce top-level menu this hangs from.
	 */
	private const PARENT_SLUG = 'woocommerce';

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
	 * Register the submenu page.
	 */
	public function register(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Coupon Audit', 'coupon-audit-and-analytics-for-woocommerce' ),
			__( 'Coupon Audit', 'coupon-audit-and-analytics-for-woocommerce' ),
			InventoryPage::CAPABILITY,
			self::PAGE_SLUG,
			array( $this->inventory, 'render' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Coupon Margin', 'coupon-audit-and-analytics-for-woocommerce' ),
			__( 'Coupon Margin', 'coupon-audit-and-analytics-for-woocommerce' ),
			InventoryPage::CAPABILITY,
			MarginPage::PAGE_SLUG,
			array( $this->margins, 'render' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Coupon Audit Settings', 'coupon-audit-and-analytics-for-woocommerce' ),
			__( 'Coupon Audit Settings', 'coupon-audit-and-analytics-for-woocommerce' ),
			InventoryPage::CAPABILITY,
			SettingsPage::PAGE_SLUG,
			array( $this->settings, 'render' )
		);
	}
}
