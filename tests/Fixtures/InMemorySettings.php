<?php
/**
 * In-memory settings.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Fixtures;

use DFX\CouponAAW\Support\SettingsInterface;

/**
 * Settings that live for the length of one test.
 *
 * The backfill's whole design is that its progress survives being interrupted,
 * so a test of it has to be able to stop, look at what was stored, and start
 * again from there.
 */
final class InMemorySettings implements SettingsInterface {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $values What is stored already.
	 */
	public function __construct( private array $values = array() ) {}

	/**
	 * Read a value.
	 *
	 * @param string $key      The setting.
	 * @param mixed  $fallback What to answer when it is not set.
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		return $this->values[ $key ] ?? $fallback;
	}

	/**
	 * Read a value that must be a string.
	 *
	 * @param string $key The setting.
	 */
	public function get_string( string $key ): ?string {
		$value = $this->values[ $key ] ?? null;

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Store a value.
	 *
	 * @param string $key   The setting.
	 * @param mixed  $value What to store.
	 */
	public function set( string $key, mixed $value ): void {
		$this->values[ $key ] = $value;
	}
}
