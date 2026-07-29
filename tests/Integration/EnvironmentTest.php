<?php
/**
 * Integration environment smoke test.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Integration;

use DFX\CouponAAW\Domain\Clock\ClockInterface;
use DFX\CouponAAW\Plugin;
use DFX\CouponAAW\Repository\CouponRepositoryInterface;
use DFX\CouponAAW\Repository\WpCouponRepository;
use WP_UnitTestCase;

/**
 * Proves the harness itself works before anything is asserted about the code.
 *
 * A silently broken integration bootstrap produces a green suite that tested
 * nothing at all, which is worse than a red one.
 */
final class EnvironmentTest extends WP_UnitTestCase {

	/**
	 * WordPress is loaded and talking to a database.
	 */
	public function test_wordpress_is_loaded(): void {
		$this->assertTrue( defined( 'ABSPATH' ) );
		$this->assertNotEmpty( get_bloginfo( 'version' ) );
	}

	/**
	 * WooCommerce is loaded, and new enough for the plugin to support it.
	 */
	public function test_woocommerce_is_loaded_and_supported(): void {
		$this->assertTrue( defined( 'WC_VERSION' ) );
		$this->assertTrue(
			version_compare( WC_VERSION, '10.3', '>=' ),
			'The integration suite needs WooCommerce 10.3 or newer, found ' . WC_VERSION
		);
	}

	/**
	 * WooCommerce's own tables exist, including the analytics lookup table the
	 * coupon repository reads usage timestamps from.
	 */
	public function test_the_woocommerce_lookup_tables_exist(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'wc_order_coupon_lookup';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		$this->assertSame( $table, $found );
	}

	/**
	 * The plugin booted through its real entry point, not through a shortcut
	 * taken by the bootstrap.
	 */
	public function test_the_plugin_booted(): void {
		$this->assertTrue( Plugin::get_instance()->is_booted() );
	}

	/**
	 * The container resolves the clock, and it reports in the site's timezone
	 * rather than the server's.
	 */
	public function test_the_clock_is_wired_to_the_site_timezone(): void {
		$clock = Plugin::get_instance()->container()->get( ClockInterface::class );

		$this->assertInstanceOf( ClockInterface::class, $clock );
		$this->assertSame( wp_timezone()->getName(), $clock->now()->getTimezone()->getName() );
	}

	/**
	 * The repository binding resolves for real against a live database. The
	 * unit suite can only assert that it was registered, since building it
	 * needs a $wpdb it deliberately does not have.
	 */
	public function test_the_coupon_repository_resolves_and_queries(): void {
		$repository = Plugin::get_instance()->container()->get( CouponRepositoryInterface::class );

		$this->assertInstanceOf( WpCouponRepository::class, $repository );
		$this->assertIsInt( $repository->count() );
	}
}
