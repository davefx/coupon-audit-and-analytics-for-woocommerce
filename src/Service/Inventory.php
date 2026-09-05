<?php
/**
 * A complete coupon inventory.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Service;

use DFX\CouponAAW\Domain\Coupon\CouponStatus;
use DFX\CouponAAW\Domain\Coupon\CouponFilter;
use DFX\CouponAAW\Domain\Overlap\Overlap;

/**
 * Every coupon in the store, judged, together with the figures that summarise
 * them.
 *
 * This exists so that `InventoryService` can stay stateless. An earlier version
 * memoised the inventory inside the service, which the container shares and the
 * plugin singleton keeps alive; in a normal page load that is a harmless
 * per-request cache, but under WP-CLI or a long-running job it serves data from
 * before the caller's own writes. Handing back one immutable object instead
 * means the read happens exactly once per call and nothing can go stale.
 */
final class Inventory {

	/**
     * @var list<InventoryEntry>
     * @readonly
     */
    public array $entries;
    /**
     * @var list<Overlap>|null
     * @readonly
     */
    public ?array $overlaps = null;
    /**
     * The figures, derived once from the entries.
     *
     * @var InventorySummary
     * @readonly
     */
    public InventorySummary $summary;

	/**
	 * Constructor.
	 *
	 * @param list<InventoryEntry> $entries  Every coupon, with what the domain concluded.
	 * @param list<Overlap>|null   $overlaps Every collision, or null when the inventory
	 *                                       was too large to check on page load.
	 */
	public function __construct(
		array $entries,
		?array $overlaps = null
	) {
		$this->entries = $entries;
        $this->overlaps = $overlaps;
        $this->summary = self::summarise( $entries, $overlaps );
	}

	/**
	 * The entries somebody is asking to see.
	 *
	 * The summary is not narrowed with them, and deliberately: the tiles describe
	 * the store, and a "needs attention" count that changed as you filtered would
	 * answer a question nobody asked. Filtering says what is on screen, not what
	 * is true of the shop.
	 *
	 * @param CouponFilter $filter Which coupons to keep.
	 *
	 * @return list<InventoryEntry>
	 */
	public function matching( CouponFilter $filter ): array {
		if ( $filter->is_empty() ) {
			return $this->entries;
		}

		return array_values(
			array_filter(
				$this->entries,
				static fn ( InventoryEntry $entry ): bool => $filter->matches(
					$entry->coupon,
					$entry->status,
					$entry->orphan_reasons
				)
			)
		);
	}

	/**
	 * Whether overlap detection actually ran.
	 */
	public function overlaps_were_checked(): bool {
		return null !== $this->overlaps;
	}

	/**
	 * Build the summary figures.
	 *
	 * @param list<InventoryEntry> $entries  The judged coupons.
	 * @param list<Overlap>|null   $overlaps Every collision, or null when not checked.
	 */
	private static function summarise( array $entries, ?array $overlaps ): InventorySummary {
		// Seeded with every status rather than filled in afterwards, so that a
		// screen can show a zero rather than a gap *and* the order of the map
		// is the enum's rather than whichever status the database happened to
		// return first. Two summaries of the same shop must be the same value.
		$by_status = array();

		foreach ( CouponStatus::cases() as $status ) {
			$by_status[ $status->value ] = 0;
		}
		$orphans      = 0;
		$unrestricted = 0;

		foreach ( $entries as $entry ) {
			$key = $entry->status->value;
			++$by_status[ $key ];

			if ( $entry->is_orphan() ) {
				++$orphans;
			}

			if ( $entry->is_live_and_unrestricted() ) {
				++$unrestricted;
			}
		}

		return new InventorySummary(
			count( $entries ),
			$by_status,
			$orphans,
			$unrestricted,
			null === $overlaps ? null : count( $overlaps )
		);
	}
}
