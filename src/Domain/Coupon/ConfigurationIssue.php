<?php
/**
 * Ways a coupon is set up wrong.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Coupon;

use DFX\CouponAAW\Domain\EnumType;

/**
 * Faults in a coupon's own terms, as distinct from a coupon that is merely
 * stale.
 *
 * An orphan is a coupon nobody meant to leave running. These are coupons that
 * were never right: they will do something the shop did not intend the first
 * time anyone uses them, and they will keep doing it.
 *
 * An enum in every respect the code relies on, spelt as a class so it runs on
 * PHP 7.4; see {@see EnumType}.
 */
final class ConfigurationIssue {

	use EnumType;

	/**
	 * A fixed discount worth more than the basket it requires.
	 *
	 * A £10 coupon with a £5 minimum spend hands over £5 and takes nothing.
	 */
	public static function DISCOUNT_EXCEEDS_MINIMUM_SPEND(): self {
		return self::of( 'DISCOUNT_EXCEEDS_MINIMUM_SPEND' );
	}

	/**
	 * A fixed discount worth more than something it applies to.
	 *
	 * A £10 coupon restricted to a £6 product is a way of paying customers £4.
	 */
	public static function DISCOUNT_EXCEEDS_PRODUCT_PRICE(): self {
		return self::of( 'DISCOUNT_EXCEEDS_PRODUCT_PRICE' );
	}

	/**
	 * A fixed discount with nothing at all constraining what it applies to.
	 *
	 * Not certainly wrong, but a fixed discount on an unbounded basket with no
	 * minimum is one cheap item away from the case above.
	 */
	public static function UNBOUNDED_FIXED_DISCOUNT(): self {
		return self::of( 'UNBOUNDED_FIXED_DISCOUNT' );
	}

	/**
	 * Every case, as name to backing value: the single source of both.
	 *
	 * @return array<string, string>
	 */
	protected static function map(): array {
		return array(
			'DISCOUNT_EXCEEDS_MINIMUM_SPEND' => 'discount_exceeds_minimum_spend',
			'DISCOUNT_EXCEEDS_PRODUCT_PRICE' => 'discount_exceeds_product_price',
			'UNBOUNDED_FIXED_DISCOUNT'       => 'unbounded_fixed_discount',
		);
	}
}
