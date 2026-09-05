<?php
/**
 * Why a coupon counts as an orphan.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Coupon;

use DFX\CouponAAW\Domain\EnumType;

/**
 * The grounds on which a live coupon is judged to be a relic (§8.4).
 *
 * A coupon can qualify on more than one count, and each is separately
 * actionable, so the detector returns reasons rather than a verdict: "no expiry
 * date" is fixed by editing the coupon, "dead campaign" by deleting it.
 *
 * An enum in every respect the code relies on, spelt as a class so it runs on
 * PHP 7.4; see {@see EnumType}.
 */
final class OrphanReason {

	use EnumType;

	/**
	 * Live, with nothing scheduled to ever turn it off.
	 */
	public static function NO_EXPIRY_DATE(): self {
		return self::of( 'NO_EXPIRY_DATE' );
	}

	/**
	 * Live, but unredeemed for longer than the configured threshold.
	 */
	public static function DORMANT(): self {
		return self::of( 'DORMANT' );
	}

	/**
	 * Live, while every other code from the same campaign has expired.
	 */
	public static function DEAD_CAMPAIGN(): self {
		return self::of( 'DEAD_CAMPAIGN' );
	}

	/**
	 * Every case, as name to backing value: the single source of both.
	 *
	 * @return array<string, string>
	 */
	protected static function map(): array {
		return array(
			'NO_EXPIRY_DATE' => 'no_expiry_date',
			'DORMANT'        => 'dormant',
			'DEAD_CAMPAIGN'  => 'dead_campaign',
		);
	}
}
