<?php
/**
 * Settings contract.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Support;

/**
 * The only way this plugin reads or writes its own settings.
 *
 * §10.4 lists options among the seams that are always injected: `get_option()`
 * is never called directly, because a class that reaches for it cannot be tested
 * without a database and cannot be given a different answer by a test.
 */
interface SettingsInterface {

	/**
	 * Read one setting.
	 *
	 * @param string $key      Setting name, without the plugin prefix.
	 * @param mixed  $fallback Value to return when the setting is unset.
	 */
	public function get( string $key, mixed $fallback = null ): mixed;

	/**
	 * Read one setting as a string, or null when unset or empty.
	 *
	 * @param string $key Setting name, without the plugin prefix.
	 */
	public function get_string( string $key ): ?string;

	/**
	 * Write one setting.
	 *
	 * @param string $key   Setting name, without the plugin prefix.
	 * @param mixed  $value The value to store.
	 */
	public function set( string $key, mixed $value ): void;
}
