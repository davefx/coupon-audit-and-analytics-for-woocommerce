<?php
/**
 * Ways a coupon is set up wrong.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Coupon;

/**
 * Faults in a coupon's own terms, as distinct from a coupon that is merely
 * stale.
 *
 * An orphan is a coupon nobody meant to leave running. These are coupons that
 * were never right: they will do something the shop did not intend the first
 * time anyone uses them, and they will keep doing it.
 */
enum ConfigurationIssue: string {

	/**
	 * A fixed discount worth more than the basket it requires.
	 *
	 * A £10 coupon with a £5 minimum spend hands over £5 and takes nothing.
	 */
	case DISCOUNT_EXCEEDS_MINIMUM_SPEND = 'discount_exceeds_minimum_spend';

	/**
	 * A fixed discount worth more than something it applies to.
	 *
	 * A £10 coupon restricted to a £6 product is a way of paying customers £4.
	 */
	case DISCOUNT_EXCEEDS_PRODUCT_PRICE = 'discount_exceeds_product_price';

	/**
	 * A fixed discount with nothing at all constraining what it applies to.
	 *
	 * Not certainly wrong, but a fixed discount on an unbounded basket with no
	 * minimum is one cheap item away from the case above.
	 */
	case UNBOUNDED_FIXED_DISCOUNT = 'unbounded_fixed_discount';
}
