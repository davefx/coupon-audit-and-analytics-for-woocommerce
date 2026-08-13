<?php
/**
 * Narrowing an inventory.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Coupon;

/**
 * Which coupons somebody is asking to see.
 *
 * A shop with four hundred coupons cannot be read top to bottom, so the screen
 * is only useful if it can be asked a question. This holds the question. It says
 * nothing about whether a coupon is *wrong* — that is what the findings are for
 * — only about what is being looked at, which is why an unset filter matches
 * everything rather than nothing.
 *
 * Conditions are combined with "and", because two of them narrowing to the union
 * would widen the result as you added them, which is not how anybody expects a
 * filter to behave.
 */
final class CouponFilter {

	/**
	 * Constructor.
	 *
	 * @param string|null $discount_type WooCommerce's discount type slug, or null for any.
	 * @param bool|null   $has_expiry    Whether the coupon expires, or null for either.
	 */
	public function __construct(
		public readonly ?string $discount_type = null,
		public readonly ?bool $has_expiry = null
	) {}

	/**
	 * Whether this asks for anything at all.
	 */
	public function is_empty(): bool {
		return null === $this->discount_type && null === $this->has_expiry;
	}

	/**
	 * Whether a coupon is one of the ones being asked for.
	 *
	 * @param CouponSnapshot $coupon The coupon to judge.
	 */
	public function matches( CouponSnapshot $coupon ): bool {
		if ( null !== $this->discount_type && $coupon->terms->amount->type !== $this->discount_type ) {
			return false;
		}

		if ( null !== $this->has_expiry && ( null !== $coupon->expires_at ) !== $this->has_expiry ) {
			return false;
		}

		return true;
	}
}
