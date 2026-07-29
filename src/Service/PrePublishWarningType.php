<?php
/**
 * What a pre-publish warning is about.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Service;

/**
 * The grounds on which a coupon being edited is worth a word of caution (§9).
 *
 * None of these prevents anything. They are things a careful colleague would
 * mention before you hit publish.
 */
enum PrePublishWarningType: string {

	/**
	 * Nothing will ever turn this coupon off.
	 */
	case NO_EXPIRY_DATE = 'no_expiry_date';

	/**
	 * Nothing limits how many times it can be redeemed.
	 */
	case NO_USAGE_LIMIT = 'no_usage_limit';

	/**
	 * Another live coupon can apply to the same products.
	 */
	case OVERLAPS_EXISTING = 'overlaps_existing';
}
