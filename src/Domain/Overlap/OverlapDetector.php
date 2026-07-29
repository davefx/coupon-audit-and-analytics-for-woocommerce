<?php
/**
 * Overlap detection.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Overlap;

use DFX\CouponAAW\Domain\Coupon\CouponSnapshot;
use DFX\CouponAAW\Domain\Coupon\CouponStatus;
use DFX\CouponAAW\Domain\Coupon\StatusResolver;

/**
 * Finds pairs of coupons that can both apply to the same product (§8.3).
 *
 * Nothing in WooCommerce compares one coupon against another, so this is the
 * finding a store owner cannot reach by any other means.
 */
final class OverlapDetector {

	/**
	 * The largest inventory worth comparing while somebody waits.
	 *
	 * §8.3 requires overlap detection to run in the background rather than on
	 * page load for large inventories. The background runner belongs to a later
	 * milestone; until it exists, callers report the check as not run rather
	 * than hanging a screen pretending otherwise. The number lives here because
	 * it bounds this class's cost, and because two callers now share it.
	 */
	public const SYNCHRONOUS_LIMIT = 300;

	/**
	 * Constructor.
	 *
	 * @param StatusResolver $status Decides which coupons are worth comparing.
	 * @param ScopeIndex     $index  Narrows the pairs to compare.
	 */
	public function __construct(
		private readonly StatusResolver $status,
		private readonly ScopeIndex $index
	) {}

	/**
	 * Every overlap in the given inventory.
	 *
	 * Coupons that can never be redeemed again are dropped before anything else
	 * happens. An expired coupon collides with nothing, and reporting it would
	 * bury the findings that matter — the same reasoning that keeps dead coupons
	 * out of the orphan report.
	 *
	 * @param list<CouponSnapshot> $coupons The inventory.
	 *
	 * @return list<Overlap>
	 */
	public function detect( array $coupons ): array {
		$live = array_values(
			array_filter( $coupons, fn ( CouponSnapshot $coupon ): bool => $this->is_live( $coupon ) )
		);

		$overlaps = array();

		foreach ( $this->index->candidate_pairs( $live ) as $pair ) {
			[ $one, $other ] = $pair;

			if ( ! $one->scope->may_intersect( $other->scope ) ) {
				continue;
			}

			$overlaps[] = Overlap::between( $one, $other, $this->severity( $one, $other ) );
		}

		return $overlaps;
	}

	/**
	 * Whether a coupon can still be redeemed, now or later.
	 *
	 * @param CouponSnapshot $coupon The coupon to judge.
	 */
	private function is_live( CouponSnapshot $coupon ): bool {
		$status = $this->status->resolve( $coupon );

		return CouponStatus::ACTIVE === $status || CouponStatus::SCHEDULED === $status;
	}

	/**
	 * How badly two overlapping coupons collide.
	 *
	 * Time is checked before anything else. Two coupons that apply automatically
	 * but never coexist cannot collide, so they are the mildest finding rather
	 * than the worst — the grading is by how likely the collision is to actually
	 * happen.
	 *
	 * @param CouponSnapshot $one   One coupon.
	 * @param CouponSnapshot $other The other.
	 */
	private function severity( CouponSnapshot $one, CouponSnapshot $other ): OverlapSeverity {
		if ( ! $this->windows_overlap( $one, $other ) ) {
			return OverlapSeverity::LOW;
		}

		if ( $one->is_auto_applied && $other->is_auto_applied ) {
			return OverlapSeverity::HIGH;
		}

		return OverlapSeverity::MEDIUM;
	}

	/**
	 * Whether the two coupons are ever valid at the same moment.
	 *
	 * A missing start means the window has always been open, and a missing
	 * expiry that it never closes.
	 *
	 * @param CouponSnapshot $one   One coupon.
	 * @param CouponSnapshot $other The other.
	 */
	private function windows_overlap( CouponSnapshot $one, CouponSnapshot $other ): bool {
		$one_start   = $one->starts_at?->getTimestamp() ?? PHP_INT_MIN;
		$one_end     = $one->expires_at?->getTimestamp() ?? PHP_INT_MAX;
		$other_start = $other->starts_at?->getTimestamp() ?? PHP_INT_MIN;
		$other_end   = $other->expires_at?->getTimestamp() ?? PHP_INT_MAX;

		return $one_start <= $other_end && $other_start <= $one_end;
	}
}
