<?php
/**
 * Plugin context unit tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Support;

use DFX\CouponAAW\Support\PluginContext;
use PHPUnit\Framework\TestCase;

/**
 * The context carries the handful of values that only the main plugin file
 * knows — version, paths, slug — so nothing deeper in the codebase has to call
 * `plugin_dir_path()` or read a constant.
 */
final class PluginContextTest extends TestCase {

	/**
	 * Build a context over a fixed, fictional install path.
	 *
	 * @param string $directory Plugin directory, with or without trailing slash.
	 * @param string $base_url  Plugin URL, with or without trailing slash.
	 */
	private function context( string $directory = '/plugins/cpn/', string $base_url = 'https://shop.test/wp-content/plugins/cpn/' ): PluginContext {
		return new PluginContext(
			version: '0.1.0',
			main_file: '/plugins/cpn/coupon-audit-and-analytics-for-woocommerce.php',
			directory: $directory,
			base_url: $base_url,
			slug: 'coupon-audit-and-analytics-for-woocommerce'
		);
	}

	/**
	 * The scalar values are handed back unchanged.
	 */
	public function test_it_exposes_the_values_it_was_given(): void {
		$context = $this->context();

		$this->assertSame( '0.1.0', $context->version );
		$this->assertSame( '/plugins/cpn/coupon-audit-and-analytics-for-woocommerce.php', $context->main_file );
		$this->assertSame( 'coupon-audit-and-analytics-for-woocommerce', $context->slug );
	}

	/**
	 * A relative path is joined to the plugin directory.
	 */
	public function test_it_builds_a_path_inside_the_plugin(): void {
		$this->assertSame( '/plugins/cpn/assets/js/inventory.js', $this->context()->path( 'assets/js/inventory.js' ) );
	}

	/**
	 * A relative path is joined to the plugin URL.
	 */
	public function test_it_builds_a_url_inside_the_plugin(): void {
		$this->assertSame(
			'https://shop.test/wp-content/plugins/cpn/assets/css/inventory.css',
			$this->context()->url( 'assets/css/inventory.css' )
		);
	}

	/**
	 * With no argument the plugin root is returned, always trailing-slashed, so
	 * callers never have to guess.
	 */
	public function test_it_returns_the_trailing_slashed_root_by_default(): void {
		$context = $this->context();

		$this->assertSame( '/plugins/cpn/', $context->path() );
		$this->assertSame( 'https://shop.test/wp-content/plugins/cpn/', $context->url() );
	}

	/**
	 * The directory and URL may arrive without a trailing slash. WordPress is
	 * inconsistent about this, so the context normalises rather than trusting.
	 */
	public function test_it_normalises_a_root_given_without_a_trailing_slash(): void {
		$context = $this->context( '/plugins/cpn', 'https://shop.test/wp-content/plugins/cpn' );

		$this->assertSame( '/plugins/cpn/assets/app.js', $context->path( 'assets/app.js' ) );
		$this->assertSame( 'https://shop.test/wp-content/plugins/cpn/assets/app.js', $context->url( 'assets/app.js' ) );
	}

	/**
	 * A leading slash on the relative part does not produce a doubled slash.
	 */
	public function test_it_tolerates_a_leading_slash_on_the_relative_part(): void {
		$context = $this->context();

		$this->assertSame( '/plugins/cpn/assets/app.js', $context->path( '/assets/app.js' ) );
		$this->assertSame( 'https://shop.test/wp-content/plugins/cpn/assets/app.js', $context->url( '/assets/app.js' ) );
	}
}
