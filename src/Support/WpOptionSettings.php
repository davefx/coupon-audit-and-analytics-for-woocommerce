<?php
/**
 * Settings stored in a WordPress option.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Support;

/**
 * Keeps every setting in one option, `dfxcaaw_settings`.
 *
 * One row rather than one per setting: WordPress autoloads options, and a
 * plugin that scatters a dozen of them makes every page of the site marginally
 * slower for the sake of tidiness nobody sees. It is also the option
 * `uninstall.php` already knows to remove.
 */
final class WpOptionSettings implements SettingsInterface {

	/**
	 * The option every setting lives in.
	 */
	private const OPTION = 'dfxcaaw_settings';

	/**
     * Read one setting.
     *
     * @param string $key      Setting name, without the plugin prefix.
     * @param mixed  $fallback Value to return when the setting is unset.
     * @return mixed
     */
    public function get( string $key, $fallback = null ) {
		$settings = get_option( self::OPTION );

		if ( ! is_array( $settings ) || ! array_key_exists( $key, $settings ) ) {
			return $fallback;
		}

		return $settings[ $key ];
	}

	/**
	 * Read one setting as a string, or null when unset or empty.
	 *
	 * @param string $key Setting name, without the plugin prefix.
	 */
	public function get_string( string $key ): ?string {
		$value = $this->get( $key );

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		return $value;
	}

	/**
	 * Write one setting.
	 *
	 * @param string $key   Setting name, without the plugin prefix.
	 * @param mixed  $value The value to store.
	 */
	public function set( string $key, $value ): void {
		$settings = get_option( self::OPTION );
		$settings = is_array( $settings ) ? $settings : array();

		$settings[ $key ] = $value;

		update_option( self::OPTION, $settings );
	}
}
