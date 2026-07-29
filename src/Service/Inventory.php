<?php
/**
 * A complete coupon inventory.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Service;

use DFX\CouponAAW\Domain\Coupon\CouponStatus;

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
	 * The figures, derived once from the entries.
	 *
	 * @var InventorySummary
	 */
	public readonly InventorySummary $summary;

	/**
	 * Constructor.
	 *
	 * @param list<InventoryEntry> $entries Every coupon, with what the domain concluded.
	 */
	public function __construct( public readonly array $entries ) {
		$this->summary = self::summarise( $entries );
	}

	/**
	 * Build the summary figures.
	 *
	 * @param list<InventoryEntry> $entries The judged coupons.
	 */
	private static function summarise( array $entries ): InventorySummary {
		$by_status    = array();
		$orphans      = 0;
		$unrestricted = 0;

		foreach ( $entries as $entry ) {
			$key               = $entry->status->value;
			$by_status[ $key ] = ( $by_status[ $key ] ?? 0 ) + 1;

			if ( $entry->is_orphan() ) {
				++$orphans;
			}

			if ( $entry->is_live_and_unrestricted() ) {
				++$unrestricted;
			}
		}

		// Every status appears, so a screen can show a zero rather than a gap.
		foreach ( CouponStatus::cases() as $status ) {
			$by_status[ $status->value ] = $by_status[ $status->value ] ?? 0;
		}

		return new InventorySummary( count( $entries ), $by_status, $orphans, $unrestricted );
	}
}
