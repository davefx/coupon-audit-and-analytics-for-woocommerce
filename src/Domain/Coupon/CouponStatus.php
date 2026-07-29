<?php
/**
 * Coupon status.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Coupon;

/**
 * The condition a coupon is in, derived from its data and the current time.
 *
 * Never stored: storing it would mean a coupon whose expiry passed overnight
 * still reads as active until something rewrites it. `StatusResolver` computes
 * it on demand instead.
 *
 * The cases carry no labels. Presentation is the admin layer's job, and putting
 * a translated string here would drag WordPress into the domain (§5).
 */
enum CouponStatus: string {

	case ACTIVE    = 'active';
	case SCHEDULED = 'scheduled';
	case EXPIRED   = 'expired';
	case EXHAUSTED = 'exhausted';
	case INACTIVE  = 'inactive';

	/**
	 * Whether a coupon in this state can currently be redeemed.
	 */
	public function is_usable(): bool {
		return self::ACTIVE === $this;
	}
}
