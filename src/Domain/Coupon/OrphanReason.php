<?php
/**
 * Why a coupon counts as an orphan.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Coupon;

/**
 * The grounds on which a live coupon is judged to be a relic (§8.4).
 *
 * A coupon can qualify on more than one count, and each is separately
 * actionable, so the detector returns reasons rather than a verdict: "no expiry
 * date" is fixed by editing the coupon, "dead campaign" by deleting it.
 */
enum OrphanReason: string {

	/**
	 * Live, with nothing scheduled to ever turn it off.
	 */
	case NO_EXPIRY_DATE = 'no_expiry_date';

	/**
	 * Live, but unredeemed for longer than the configured threshold.
	 */
	case DORMANT = 'dormant';

	/**
	 * Live, while every other code from the same campaign has expired.
	 */
	case DEAD_CAMPAIGN = 'dead_campaign';
}
