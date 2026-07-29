<?php
/**
 * A collision between two coupons.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Overlap;

use DFX\CouponAAW\Domain\Coupon\CouponId;
use DFX\CouponAAW\Domain\Coupon\CouponSnapshot;

/**
 * Two coupons that can both apply to the same product, and how badly.
 *
 * The pair is ordered by ID so that the same collision always reads the same
 * way, whatever order the repository returned its rows in.
 */
final class Overlap {

	/**
	 * Constructor.
	 *
	 * @param CouponSnapshot  $one      The lower-numbered coupon.
	 * @param CouponSnapshot  $other    The higher-numbered coupon.
	 * @param OverlapSeverity $severity How likely the collision is to happen.
	 */
	public function __construct(
		public readonly CouponSnapshot $one,
		public readonly CouponSnapshot $other,
		public readonly OverlapSeverity $severity
	) {}

	/**
	 * Build an overlap with its pair in a stable order.
	 *
	 * @param CouponSnapshot  $a        Either coupon.
	 * @param CouponSnapshot  $b        The other coupon.
	 * @param OverlapSeverity $severity How likely the collision is to happen.
	 */
	public static function between( CouponSnapshot $a, CouponSnapshot $b, OverlapSeverity $severity ): self {
		return $a->id->value <= $b->id->value
			? new self( $a, $b, $severity )
			: new self( $b, $a, $severity );
	}

	/**
	 * Whether a given coupon is one of the two.
	 *
	 * @param CouponId $id The coupon to look for.
	 */
	public function involves( CouponId $id ): bool {
		return $this->one->id->equals( $id ) || $this->other->id->equals( $id );
	}

	/**
	 * The coupon on the other side of the collision from the one given.
	 *
	 * @param CouponId $id One of the two coupons.
	 */
	public function counterpart( CouponId $id ): CouponSnapshot {
		return $this->one->id->equals( $id ) ? $this->other : $this->one;
	}
}
