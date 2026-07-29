<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads nothing but the Composer autoloader. Unit tests must run without
 * WordPress: WordPress functions are stubbed per test case with Brain Monkey.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

$dfxcaaw_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! is_readable( $dfxcaaw_autoload ) ) {
	fwrite( STDERR, "Composer dependencies are missing. Run `composer install` first.\n" );
	exit( 1 );
}

require_once $dfxcaaw_autoload;
