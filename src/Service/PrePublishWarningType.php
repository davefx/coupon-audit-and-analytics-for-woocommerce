<?php
/**
 * What a pre-publish warning is about.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Service;

use DFX\CouponAAW\Domain\EnumType;

/**
 * The grounds on which a coupon being edited is worth a word of caution (§9).
 *
 * None of these prevents anything. They are things a careful colleague would
 * mention before you hit publish.
 *
 * An enum in every respect the code relies on, spelt as a class so it runs on
 * PHP 7.4; see {@see EnumType}.
 */
final class PrePublishWarningType {

	use EnumType;

	/**
	 * Nothing will ever turn this coupon off.
	 */
	public static function NO_EXPIRY_DATE(): self {
		return self::of( 'NO_EXPIRY_DATE' );
	}

	/**
	 * Nothing limits how many times it can be redeemed.
	 */
	public static function NO_USAGE_LIMIT(): self {
		return self::of( 'NO_USAGE_LIMIT' );
	}

	/**
	 * Another live coupon can apply to the same products.
	 */
	public static function OVERLAPS_EXISTING(): self {
		return self::of( 'OVERLAPS_EXISTING' );
	}

	/**
	 * Every case, as name to backing value: the single source of both.
	 *
	 * @return array<string, string>
	 */
	protected static function map(): array {
		return array(
			'NO_EXPIRY_DATE'    => 'no_expiry_date',
			'NO_USAGE_LIMIT'    => 'no_usage_limit',
			'OVERLAPS_EXISTING' => 'overlaps_existing',
		);
	}
}
