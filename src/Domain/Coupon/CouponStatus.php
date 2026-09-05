<?php
/**
 * Coupon status.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Coupon;

use DFX\CouponAAW\Domain\EnumType;

/**
 * The condition a coupon is in, derived from its data and the current time.
 *
 * Never stored: storing it would mean a coupon whose expiry passed overnight
 * still reads as active until something rewrites it. `StatusResolver` computes
 * it on demand instead.
 *
 * The cases carry no labels. Presentation is the admin layer's job, and putting
 * a translated string here would drag WordPress into the domain (§5).
 *
 * An enum in every respect the code relies on, spelt as a class so it runs on
 * PHP 7.4; see {@see EnumType}.
 */
final class CouponStatus {

	use EnumType;

	/**
	 * Live now: published, started, not expired, not used up.
	 */
	public static function ACTIVE(): self {
		return self::of( 'ACTIVE' );
	}

	/**
	 * Published, but its start date is still in the future.
	 */
	public static function SCHEDULED(): self {
		return self::of( 'SCHEDULED' );
	}

	/**
	 * Past its expiry date.
	 */
	public static function EXPIRED(): self {
		return self::of( 'EXPIRED' );
	}

	/**
	 * Redeemed as many times as it is allowed to be.
	 */
	public static function EXHAUSTED(): self {
		return self::of( 'EXHAUSTED' );
	}

	/**
	 * Not published, so it cannot be redeemed whatever its dates say.
	 */
	public static function INACTIVE(): self {
		return self::of( 'INACTIVE' );
	}

	/**
	 * Every case, as name to backing value: the single source of both.
	 *
	 * @return array<string, string>
	 */
	protected static function map(): array {
		return array(
			'ACTIVE'    => 'active',
			'SCHEDULED' => 'scheduled',
			'EXPIRED'   => 'expired',
			'EXHAUSTED' => 'exhausted',
			'INACTIVE'  => 'inactive',
		);
	}

	/**
	 * Whether a coupon in this state can currently be redeemed.
	 */
	public function is_usable(): bool {
		return self::ACTIVE() === $this;
	}
}
