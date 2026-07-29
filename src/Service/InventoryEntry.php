<?php
/**
 * One row of the coupon inventory.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Service;

use DFX\CouponAAW\Domain\Coupon\CouponSnapshot;
use DFX\CouponAAW\Domain\Coupon\CouponStatus;
use DFX\CouponAAW\Domain\Coupon\OrphanReason;

/**
 * A coupon together with everything the domain concluded about it.
 *
 * Both conclusions are expensive to reach — status needs a clock, orphanhood
 * needs the whole inventory — so they are computed once, here, rather than
 * recomputed by every column of every row that wants to display them.
 */
final class InventoryEntry {

	/**
	 * Constructor.
	 *
	 * @param CouponSnapshot     $coupon         The coupon as stored.
	 * @param CouponStatus       $status         Its resolved status.
	 * @param list<OrphanReason> $orphan_reasons Every ground on which it is a relic, possibly none.
	 */
	public function __construct(
		public readonly CouponSnapshot $coupon,
		public readonly CouponStatus $status,
		public readonly array $orphan_reasons
	) {}

	/**
	 * Whether the coupon was flagged on any ground at all.
	 */
	public function is_orphan(): bool {
		return array() !== $this->orphan_reasons;
	}

	/**
	 * Whether the coupon is live and applies to the entire catalogue.
	 */
	public function is_live_and_unrestricted(): bool {
		return $this->status->is_usable() && $this->coupon->scope->is_universal();
	}
}
