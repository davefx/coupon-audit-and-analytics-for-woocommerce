<?php
/**
 * PHPUnit bootstrap for the integration suite.
 *
 * Unlike the unit bootstrap, this one loads a real WordPress, a real
 * WooCommerce and a real database. It is deliberately a separate file: the unit
 * suite must never pay for any of that (§10.1).
 *
 * Locations are read from the environment so the same file works on a developer
 * machine and in CI:
 *
 * - WP_TESTS_DIR    the WordPress core test library   (default /tmp/wordpress-tests-lib)
 * - WP_CORE_DIR     the WordPress install under test  (default /tmp/wordpress)
 * - WC_PLUGIN_DIR   the WooCommerce plugin directory  (default inside WP_CORE_DIR)
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

/*
 * The WordPress core test suite refuses to start without the Yoast polyfills
 * and looks for them at this constant rather than on the autoloader.
 */
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

/**
 * Read a directory from the environment, falling back to a default.
 *
 * @param string $name     Environment variable name.
 * @param string $fallback Value to use when unset.
 */
function dfxcaaw_tests_dir( string $name, string $fallback ): string {
	$value = getenv( $name );

	return rtrim( is_string( $value ) && '' !== $value ? $value : $fallback, '/' );
}

$dfxcaaw_tests_dir = dfxcaaw_tests_dir( 'WP_TESTS_DIR', '/tmp/wordpress-tests-lib' );
$dfxcaaw_core_dir  = dfxcaaw_tests_dir( 'WP_CORE_DIR', '/tmp/wordpress' );
$dfxcaaw_wc_dir    = dfxcaaw_tests_dir( 'WC_PLUGIN_DIR', $dfxcaaw_core_dir . '/wp-content/plugins/woocommerce' );

if ( ! is_readable( $dfxcaaw_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		sprintf(
			"The WordPress test library was not found at %s.\nRun bin/install-wp-tests.sh, or set WP_TESTS_DIR.\n",
			$dfxcaaw_tests_dir
		)
	);
	exit( 1 );
}

if ( ! is_readable( $dfxcaaw_wc_dir . '/woocommerce.php' ) ) {
	fwrite(
		STDERR,
		sprintf(
			"WooCommerce was not found at %s.\nSet WC_PLUGIN_DIR to its directory.\n",
			$dfxcaaw_wc_dir
		)
	);
	exit( 1 );
}

require_once $dfxcaaw_tests_dir . '/includes/functions.php';

/*
 * WooCommerce first, then this plugin. The plugin's own guard refuses to boot
 * without a supported WooCommerce, so loading them the other way round would
 * produce a suite that silently tests nothing.
 */
tests_add_filter(
	'muplugins_loaded',
	static function () use ( $dfxcaaw_wc_dir ): void {
		require_once $dfxcaaw_wc_dir . '/woocommerce.php';
		require_once dirname( __DIR__ ) . '/coupon-audit-and-analytics-for-woocommerce.php';
	}
);

/*
 * WooCommerce keeps its own tables, including the analytics lookup tables this
 * plugin reads. The core test installer knows nothing about them, so they are
 * created here before any test runs.
 */
tests_add_filter(
	'setup_theme',
	static function (): void {
		WC_Install::install();

		// Roles are cached from before WooCommerce added its own.
		$GLOBALS['wp_roles'] = null;
		wp_roles();
	}
);

require $dfxcaaw_tests_dir . '/includes/bootstrap.php';
