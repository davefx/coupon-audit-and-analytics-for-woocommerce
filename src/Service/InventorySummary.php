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
     * @var int
     * @readonly
     */
    public int $total;
    /**
     * @var array<string, int>
     * @readonly
     */
    public array $by_status;
    /**
     * @var int
     * @readonly
     */
    public int $orphans;
    /**
     * @var int
     * @readonly
     */
    public int $unrestricted;
    /**
     * @var int|null
     * @readonly
     */
    public ?int $overlaps = null;
    /**
	 * Constructor.
	 *
	 * @param int                $total        Coupons in the store.
	 * @param array<string, int> $by_status    Counts keyed by status value.
	 * @param int                $orphans      Coupons flagged as relics.
	 * @param int                $unrestricted Live coupons that apply to the whole catalogue.
	 * @param int|null           $overlaps     Colliding pairs, or null when the inventory was too large to check.
	 */
	public function __construct(int $total, array $by_status, int $orphans, int $unrestricted, ?int $overlaps = null)
    {
        $this->total = $total;
        $this->by_status = $by_status;
        $this->orphans = $orphans;
        $this->unrestricted = $unrestricted;
        $this->overlaps = $overlaps;
    }

	/**
	 * How many coupons are in a given status.
	 *
	 * @param CouponStatus $status The status to count.
	 */
	public function of( CouponStatus $status ): int {
		return $this->by_status[ $status->value ] ?? 0;
	}
}
