<?php
/**
 * Coupon status resolution.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Coupon;

use DFX\CouponAAW\Domain\Clock\ClockInterface;

/**
 * Derives the status of a coupon from its data and the current time (§8.1).
 */
final class StatusResolver {

	/**
	 * Constructor.
	 *
	 * @param ClockInterface $clock Supplies the current instant.
	 */
	public function __construct( private readonly ClockInterface $clock ) {}

	/**
	 * Resolve a coupon's status.
	 *
	 * The order of the checks below is the whole of the logic, and each step is
	 * a deliberate precedence decision:
	 *
	 * - An unpublished coupon is off. Why it would otherwise be off is noise.
	 * - Expiry beats exhaustion, because raising a usage limit revives an
	 *   exhausted coupon while an expired one stays dead until its date moves.
	 *   Reporting the condition the user cannot lift is the more useful of the
	 *   two.
	 * - Exhaustion beats scheduling: a coupon that can never be redeemed is not
	 *   meaningfully "coming soon".
	 *
	 * @param Judgeable $coupon The coupon, or a projection of one.
	 */
	public function resolve( Judgeable $coupon ): CouponStatus {
		if ( ! $coupon->is_published() ) {
			return CouponStatus::INACTIVE;
		}

		$now = $this->clock->now();

		// Strictly later, matching how WooCommerce itself decides a coupon has
		// expired. A coupon the storefront still accepts must not be reported
		// here as dead.
		$expires_at = $coupon->expires_at();

		if ( null !== $expires_at && $now > $expires_at ) {
			return CouponStatus::EXPIRED;
		}

		if ( $coupon->has_reached_usage_limit() ) {
			return CouponStatus::EXHAUSTED;
		}

		$starts_at = $coupon->starts_at();

		if ( null !== $starts_at && $starts_at > $now ) {
			return CouponStatus::SCHEDULED;
		}

		return CouponStatus::ACTIVE;
	}
}
