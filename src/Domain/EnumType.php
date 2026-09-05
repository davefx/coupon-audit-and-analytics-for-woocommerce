<?php
/**
 * Enum-like behaviour for PHP 7.4.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain;

/**
 * Gives a plain class the part of an enum this code relies on, so the domain can
 * run on PHP 7.4 where the `enum` keyword does not exist.
 *
 * A class that uses this trait declares one static method per case, each
 * returning `self::of( 'NAME' )`, and a `map()` of case name to backing value.
 * Every case is a single shared instance, so `===` decides identity exactly as
 * it does for a real backed enum — which is what lets `self::ACTIVE() === $this`
 * and the switch statements that replaced `match` keep working unchanged.
 *
 * `$value` and `$name` are public and behave like the accessors a backed enum
 * exposes; `from()`, `tryFrom()` and `cases()` mirror the built-ins. `from()`
 * throws `\InvalidArgumentException` rather than the `\ValueError` a real enum
 * would, because that class does not exist before PHP 8.0 and nothing here
 * catches it.
 */
trait EnumType {

	/**
	 * The case name, e.g. `ACTIVE`. Mirrors a backed enum's `->name`.
	 *
	 * @readonly
	 * @var string
	 */
	public $name;

	/**
	 * The backing value, e.g. `active`. Mirrors a backed enum's `->value`.
	 *
	 * @readonly
	 * @var string
	 */
	public $value;

	/**
	 * One instance per case, so identity comparison behaves like an enum.
	 *
	 * A trait's static property is not shared between the classes that use it,
	 * so each enum class keeps its own pool and the keys cannot collide.
	 *
	 * @var array<string, self>
	 */
	private static $pool = array();

	/**
	 * Private, so cases can only be the shared instances `of()` hands out.
	 *
	 * @param string $name  The case name.
	 * @param string $value The backing value.
	 */
	final private function __construct( string $name, string $value ) {
		$this->name  = $name;
		$this->value = $value;
	}

	/**
	 * Case name to backing value, the single source of truth for both.
	 *
	 * @return array<string, string>
	 */
	abstract protected static function map(): array;

	/**
	 * The one instance for a case, created on first use and reused after.
	 *
	 * @param string $name The case name.
	 *
	 * @throws \InvalidArgumentException When no case has that name.
	 */
	private static function of( string $name ): self {
		$map = static::map();

		if ( ! isset( $map[ $name ] ) ) {
			throw new \InvalidArgumentException(
				sprintf( '%s has no case named "%s".', static::class, $name )
			);
		}

		if ( ! isset( self::$pool[ $name ] ) ) {
			self::$pool[ $name ] = new static( $name, $map[ $name ] );
		}

		return self::$pool[ $name ];
	}

	/**
	 * Every case, in declaration order. Mirrors the enum built-in.
	 *
	 * @return list<self>
	 */
	public static function cases(): array {
		$cases = array();

		foreach ( array_keys( static::map() ) as $name ) {
			$cases[] = static::of( $name );
		}

		return $cases;
	}

	/**
	 * The case for a backing value, or an exception. Mirrors the enum built-in.
	 *
	 * @param string $value The backing value to look up.
	 *
	 * @throws \InvalidArgumentException When no case has that value.
	 */
	public static function from( string $value ): self {
		$case = static::tryFrom( $value );

		if ( null === $case ) {
			throw new \InvalidArgumentException(
				sprintf( '"%s" is not a valid backing value for %s.', $value, static::class )
			);
		}

		return $case;
	}

	/**
	 * The case for a backing value, or null. Mirrors the enum built-in.
	 *
	 * @param string $value The backing value to look up.
	 */
	public static function tryFrom( string $value ): ?self {
		foreach ( static::map() as $name => $backing ) {
			if ( $backing === $value ) {
				return static::of( $name );
			}
		}

		return null;
	}
}
