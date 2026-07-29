<?php
/**
 * Plugin Name:          Coupon Audit and Analytics for WooCommerce
 * Plugin URI:           https://github.com/davefx/coupon-audit-and-analytics-for-woocommerce
 * Description:          Audits your coupon inventory — what is live, what it really applies to, what overlaps — and measures what each coupon actually earns.
 * Version:              0.1.0
 * Requires at least:    6.4
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * Author:               David Marín Carreño
 * Author URI:           https://davefx.com
 * License:              GPLv3 or later
 * License URI:          https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:          coupon-audit-and-analytics-for-woocommerce
 * WC requires at least: 10.3
 * WC tested up to:      10.3
 *
 * @package DFX\CouponAAW
 */

defined( 'ABSPATH' ) || exit;

/*
 * This file is the plugin boundary and the only place that knows the public
 * name. It must stay parseable by PHP 7, or the version guard below would
 * itself be a syntax error on the installs it exists to protect. Everything
 * under src/ is free to use the full PHP 8.1 feature set.
 */

define( 'DFXCAAW_VERSION', '0.1.0' );
define( 'DFXCAAW_FILE', __FILE__ );
define( 'DFXCAAW_SLUG', 'coupon-audit-and-analytics-for-woocommerce' );
define( 'DFXCAAW_MIN_PHP', '8.1' );
define( 'DFXCAAW_MIN_WC', '10.3' );

/**
 * Queue an admin notice.
 *
 * The message is built inside the `admin_notices` callback rather than at call
 * time, so no translation function runs before `init`.
 *
 * @param callable $message Returns the translated message.
 *
 * @return void
 */
function dfxcaaw_admin_notice( $message ) {
	add_action(
		'admin_notices',
		function () use ( $message ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( call_user_func( $message ) )
			);
		}
	);
}

if ( version_compare( PHP_VERSION, DFXCAAW_MIN_PHP, '<' ) ) {
	dfxcaaw_admin_notice(
		function () {
			return sprintf(
				/* translators: 1: required PHP version, 2: PHP version in use. */
				__( 'Coupon Audit and Analytics for WooCommerce requires PHP %1$s or newer. This site runs PHP %2$s.', 'coupon-audit-and-analytics-for-woocommerce' ),
				DFXCAAW_MIN_PHP,
				PHP_VERSION
			);
		}
	);

	return;
}

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	dfxcaaw_admin_notice(
		function () {
			return __( 'Coupon Audit and Analytics for WooCommerce is missing its dependencies. Run "composer install" in the plugin directory.', 'coupon-audit-and-analytics-for-woocommerce' );
		}
	);

	return;
}

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Declare compatibility with the WooCommerce features this plugin coexists with.
 *
 * The plugin reads order data and never touches the storefront, but both
 * declarations are required: without them WooCommerce flags it as incompatible
 * and refuses to enable HPOS (§2).
 *
 * @return void
 */
function dfxcaaw_declare_compatibility() {
	if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		return;
	}

	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DFXCAAW_FILE, true );
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DFXCAAW_FILE, true );
}

add_action( 'before_woocommerce_init', 'dfxcaaw_declare_compatibility' );

/**
 * Whether a supported WooCommerce is present.
 *
 * @return bool
 */
function dfxcaaw_woocommerce_is_supported() {
	return defined( 'WC_VERSION' ) && version_compare( WC_VERSION, DFXCAAW_MIN_WC, '>=' );
}

/**
 * Boot the plugin.
 *
 * The only work done here is assembling the context that the main file alone
 * knows and handing it to the providers. All wiring lives in the providers,
 * all logic lives below them.
 *
 * @return void
 */
function dfxcaaw_bootstrap() {
	if ( ! dfxcaaw_woocommerce_is_supported() ) {
		dfxcaaw_admin_notice(
			function () {
				return sprintf(
					/* translators: %s: required WooCommerce version. */
					__( 'Coupon Audit and Analytics for WooCommerce requires WooCommerce %s or newer to be installed and active.', 'coupon-audit-and-analytics-for-woocommerce' ),
					DFXCAAW_MIN_WC
				);
			}
		);

		return;
	}

	$context = new \DFX\CouponAAW\Support\PluginContext(
		DFXCAAW_VERSION,
		DFXCAAW_FILE,
		plugin_dir_path( DFXCAAW_FILE ),
		plugin_dir_url( DFXCAAW_FILE ),
		DFXCAAW_SLUG
	);

	\DFX\CouponAAW\Plugin::get_instance()
		->add_provider( new \DFX\CouponAAW\Providers\CoreServiceProvider( $context, wp_timezone() ) )
		->add_provider( new \DFX\CouponAAW\Providers\AdminServiceProvider() )
		->boot();
}

add_action( 'plugins_loaded', 'dfxcaaw_bootstrap', 20 );

/**
 * Prepare the store when the plugin is switched on.
 *
 * Activation runs before `plugins_loaded`, so the plugin is booted here on
 * demand rather than assumed to be running already.
 *
 * @return void
 */
function dfxcaaw_activate() {
	if ( ! dfxcaaw_woocommerce_is_supported() ) {
		return;
	}

	dfxcaaw_bootstrap();

	\DFX\CouponAAW\Plugin::get_instance()
		->container()
		->get( \DFX\CouponAAW\Install\Activator::class )
		->activate();
}

register_activation_hook( DFXCAAW_FILE, 'dfxcaaw_activate' );
