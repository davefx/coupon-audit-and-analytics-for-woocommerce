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
use DFX\CouponAAW\Domain\Coupon\ConfigurationIssue;
use DFX\CouponAAW\Domain\Coupon\OrphanReason;
use DFX\CouponAAW\Domain\Overlap\Overlap;
use DFX\CouponAAW\Domain\Overlap\OverlapSeverity;

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
	 * @param CouponSnapshot           $coupon         The coupon as stored.
	 * @param CouponStatus             $status         Its resolved status.
	 * @param list<OrphanReason>       $orphan_reasons Every ground on which it is a relic, possibly none.
	 * @param list<Overlap>            $overlaps  Collisions this coupon takes part in.
	 * @param list<ConfigurationIssue> $issues   Faults in the coupon's own terms.
	 */
	public function __construct(
		public readonly CouponSnapshot $coupon,
		public readonly CouponStatus $status,
		public readonly array $orphan_reasons,
		public readonly array $overlaps = array(),
		public readonly array $issues = array()
	) {}

	/**
	 * Whether anything at all was found against this coupon.
	 */
	public function has_findings(): bool {
		return $this->is_orphan() || array() !== $this->issues || array() !== $this->overlaps;
	}

	/**
	 * The worst collision this coupon takes part in, if any.
	 */
	public function worst_overlap(): ?OverlapSeverity {
		$worst = null;

		foreach ( $this->overlaps as $overlap ) {
			if ( null === $worst || $overlap->severity->rank() > $worst->rank() ) {
				$worst = $overlap->severity;
			}
		}

		return $worst;
	}

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
