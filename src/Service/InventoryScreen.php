<?php
/**
 * One page of the coupon inventory.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Service;

/**
 * What the audit screen needs in order to draw itself: the figures above the
 * table, the rows of one page, and how many rows there are altogether.
 *
 * It is deliberately not an `Inventory`. An inventory is every coupon in the
 * shop, and the whole point of this is that the screen no longer holds one.
 */
final class InventoryScreen {

	/**
     * @var InventorySummary
     * @readonly
     */
    public InventorySummary $summary;
    /**
     * @var list<InventoryEntry>
     * @readonly
     */
    public array $entries;
    /**
     * @var int
     * @readonly
     */
    public int $total;
    /**
     * @var bool
     * @readonly
     */
    public bool $overlaps_known = true;
    /**
	 * Constructor.
	 *
	 * @param InventorySummary     $summary        The figures, describing the whole shop.
	 * @param list<InventoryEntry> $entries        The rows of the page asked for.
	 * @param int                  $total          How many coupons matched the filter.
	 * @param bool                 $overlaps_known Whether overlap detection ran at all.
	 */
	public function __construct(InventorySummary $summary, array $entries, int $total, bool $overlaps_known = true)
    {
        $this->summary = $summary;
        $this->entries = $entries;
        $this->total = $total;
        $this->overlaps_known = $overlaps_known;
    }
}
