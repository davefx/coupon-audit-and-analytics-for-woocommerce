<?php
/**
 * Inventory screen integration tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Integration\Admin;

use DFX\CouponAAW\Admin\AssetLoader;
use DFX\CouponAAW\Admin\InventoryPage;
use DFX\CouponAAW\Admin\MenuRegistrar;
use DFX\CouponAAW\Plugin;
use WC_Coupon;
use WP_UnitTestCase;

/**
 * The screen is the first thing a user sees and the last thing covered by unit
 * tests: capabilities, escaping and menu registration only mean anything inside
 * a real WordPress.
 */
final class InventoryPageTest extends WP_UnitTestCase {

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		// Enqueued styles survive between tests in one process; each test here
		// asserts about enqueuing, so each starts from nothing.
		wp_dequeue_style( 'dfxcaaw-inventory-page' );
	}

	/**
	 * Sign in as a user with a given role.
	 *
	 * @param string $role The role to sign in as.
	 */
	private function login_as( string $role ): void {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		$this->assertIsInt( $user_id );

		wp_set_current_user( $user_id );
	}

	/**
	 * Render the screen and capture its markup.
	 */
	private function render(): string {
		$container = Plugin::get_instance()->container();

		ob_start();

		try {
			$container->get( InventoryPage::class )->render();
		} finally {
			// wp_die() throws out of render(), and an abandoned buffer makes
			// every later test in the process report as risky.
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * Create a coupon.
	 *
	 * @param string      $code    The coupon code.
	 * @param string|null $expires Expiry date, or null for none.
	 */
	private function create_coupon( string $code, ?string $expires = null ): int {
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 10 );

		if ( null !== $expires ) {
			$coupon->set_date_expires( $expires );
		}

		return $coupon->save();
	}

	/**
	 * A shop manager can see the screen; the menu is gated on the same
	 * capability §14 requires.
	 */
	public function test_the_menu_is_registered_under_woocommerce(): void {
		$this->login_as( 'administrator' );

		$GLOBALS['submenu'] = array();

		Plugin::get_instance()->container()->get( MenuRegistrar::class )->register();

		$menus = $GLOBALS['submenu'];
		$this->assertIsArray( $menus );
		$this->assertArrayHasKey( 'woocommerce', $menus, 'The screen must hang from the WooCommerce menu.' );

		$entry = array_values(
			array_filter(
				$menus['woocommerce'],
				static fn ( array $item ): bool => MenuRegistrar::PAGE_SLUG === $item[2]
			)
		);

		$this->assertCount( 1, $entry );
		$this->assertSame( InventoryPage::CAPABILITY, $entry[0][1], 'The menu must be gated on the capability §14 requires.' );
	}

	/**
	 * The page callback refuses a user without the capability, even though the
	 * menu would never have shown it to them. A page callback is reachable by
	 * URL regardless of what the menu decided.
	 */
	public function test_rendering_is_refused_without_the_capability(): void {
		$this->login_as( 'subscriber' );

		$this->expectException( \WPDieException::class );

		$this->render();
	}

	/**
	 * With coupons present, the table lists them.
	 */
	public function test_it_lists_the_stores_coupons(): void {
		$this->login_as( 'administrator' );

		$this->create_coupon( 'summersale', '2026-12-01' );
		$this->create_coupon( 'wintersale', '2026-12-01' );

		$html = $this->render();

		$this->assertStringContainsString( 'summersale', $html );
		$this->assertStringContainsString( 'wintersale', $html );
	}

	/**
	 * A coupon with no expiry is both listed and flagged, which is the entire
	 * point of the screen.
	 */
	public function test_it_flags_a_coupon_that_never_expires(): void {
		$this->login_as( 'administrator' );

		$this->create_coupon( 'forever' );

		$html = $this->render();

		$this->assertStringContainsString( 'forever', $html );
		$this->assertStringContainsString( 'No expiry date', $html );
		$this->assertStringContainsString( 'Needs attention', $html );
	}

	/**
	 * An empty store gets an empty table rather than a fatal error.
	 */
	public function test_an_empty_store_renders_without_rows(): void {
		$this->login_as( 'administrator' );

		$html = $this->render();

		$this->assertStringContainsString( 'No coupons found.', $html );
	}

	/**
	 * A code containing markup never reaches the page as markup. WooCommerce
	 * strips tags from codes itself, so this guards the belt rather than the
	 * braces — but the screen must not depend on an upstream sanitiser it does
	 * not control.
	 */
	public function test_it_escapes_coupon_codes(): void {
		$this->login_as( 'administrator' );

		$this->create_coupon( '<script>alert(1)</script>', '2026-12-01' );

		$html = $this->render();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
	}

	/**
	 * WooCommerce stores coupon codes HTML-encoded: a coupon called "A&B" is
	 * held as "a&amp;b". Escaping that as-is would show the entity on screen,
	 * so it is decoded before being escaped again — a user must see the code
	 * their customers type.
	 */
	public function test_it_displays_an_encoded_code_the_way_a_customer_types_it(): void {
		$this->login_as( 'administrator' );

		$this->create_coupon( 'A&B', '2026-12-01' );

		$html = $this->render();

		$this->assertStringContainsString( '>a&amp;b<', $html );
		$this->assertStringNotContainsString( 'a&amp;amp;b', $html );
	}

	/**
	 * The stylesheet loads on the audit screen.
	 */
	public function test_the_stylesheet_loads_on_the_inventory_screen(): void {
		$loader = Plugin::get_instance()->container()->get( AssetLoader::class );

		$loader->enqueue( 'woocommerce_page_' . MenuRegistrar::PAGE_SLUG );

		$this->assertTrue( wp_style_is( 'dfxcaaw-inventory-page', 'enqueued' ) );
	}

	/**
	 * And nowhere else. Enqueuing on every admin screen slows down every other
	 * plugin's pages, which earns an uninstall rather than a bug report.
	 */
	public function test_the_stylesheet_does_not_load_elsewhere(): void {
		$loader = Plugin::get_instance()->container()->get( AssetLoader::class );

		$loader->enqueue( 'edit.php' );

		$this->assertFalse( wp_style_is( 'dfxcaaw-inventory-page', 'enqueued' ) );
	}
}
