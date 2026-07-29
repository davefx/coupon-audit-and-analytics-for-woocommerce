<?php
/**
 * Inventory summary.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Service;

use DFX\CouponAAW\Domain\Coupon\CouponStatus;

/**
 * The figures shown above the inventory table.
 *
 * This is the part a store owner reads first and often the only part they read,
 * so it answers the two questions the audit exists to answer: how many coupons
 * are live, and how many of them should not be.
 */
final class InventorySummary {

	/**
	 * Constructor.
	 *
	 * @param int                $total        Coupons in the store.
	 * @param array<string, int> $by_status    Counts keyed by status value.
	 * @param int                $orphans      Coupons flagged as relics.
	 * @param int                $unrestricted Live coupons that apply to the whole catalogue.
	 * @param int|null           $overlaps     Colliding pairs, or null when the inventory was too large to check.
	 */
	public function __construct(
		public readonly int $total,
		public readonly array $by_status,
		public readonly int $orphans,
		public readonly int $unrestricted,
		public readonly ?int $overlaps = null
	) {}

	/**
	 * How many coupons are in a given status.
	 *
	 * @param CouponStatus $status The status to count.
	 */
	public function of( CouponStatus $status ): int {
		return $this->by_status[ $status->value ] ?? 0;
	}
}
