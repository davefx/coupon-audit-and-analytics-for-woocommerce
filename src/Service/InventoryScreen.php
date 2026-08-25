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
	 * Constructor.
	 *
	 * @param InventorySummary     $summary        The figures, describing the whole shop.
	 * @param list<InventoryEntry> $entries        The rows of the page asked for.
	 * @param int                  $total          How many coupons matched the filter.
	 * @param bool                 $overlaps_known Whether overlap detection ran at all.
	 */
	public function __construct(
		public readonly InventorySummary $summary,
		public readonly array $entries,
		public readonly int $total,
		public readonly bool $overlaps_known = true
	) {}
}
