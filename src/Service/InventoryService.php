<?php
/**
 * Inventory orchestration.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Service;

use DFX\CouponAAW\Domain\Coupon\OrphanDetector;
use DFX\CouponAAW\Domain\Coupon\StatusResolver;
use DFX\CouponAAW\Domain\Overlap\Overlap;
use DFX\CouponAAW\Domain\Overlap\OverlapDetector;
use DFX\CouponAAW\Repository\CouponRepositoryInterface;

/**
 * Builds the coupon inventory.
 *
 * Orchestration only: it coordinates the repository and the domain and
 * calculates nothing itself (§5). It holds no state between calls, so two
 * builds either side of a write see the world either side of that write.
 */
final class InventoryService {

	/**
	 * Above this many coupons, overlap detection is skipped on page load.
	 *
	 * §8.3 requires overlap detection to run in the background rather than on
	 * page load for large inventories. The background runner belongs to a later
	 * milestone; until it exists, the honest thing is to say the check was not
	 * run rather than to hang the screen pretending otherwise. The index makes
	 * a narrowly-scoped inventory of this size cheap, but one where every coupon
	 * applies to everything is quadratic however it is indexed.
	 */
	public const OVERLAP_LIMIT = 300;

	/**
	 * Constructor.
	 *
	 * @param CouponRepositoryInterface $coupons  Source of coupons.
	 * @param StatusResolver            $status   Resolves each coupon's status.
	 * @param OrphanDetector            $orphans  Judges each coupon against the rest.
	 * @param OverlapDetector           $overlaps Finds colliding pairs.
	 */
	public function __construct(
		private readonly CouponRepositoryInterface $coupons,
		private readonly StatusResolver $status,
		private readonly OrphanDetector $orphans,
		private readonly OverlapDetector $overlaps
	) {}

	/**
	 * Read every coupon and judge it.
	 *
	 * The whole inventory is read in one go, which is not merely an
	 * optimisation: both the dead-campaign rule and overlap detection judge a
	 * coupon against every other coupon, so the full set has to be in hand
	 * before any single entry can be built.
	 */
	public function build(): Inventory {
		$coupons = $this->coupons->all();

		$overlaps = count( $coupons ) > self::OVERLAP_LIMIT
			? null
			: $this->overlaps->detect( $coupons );

		$by_coupon = $this->group_by_coupon( $overlaps ?? array() );
		$entries   = array();

		foreach ( $coupons as $coupon ) {
			$entries[] = new InventoryEntry(
				$coupon,
				$this->status->resolve( $coupon ),
				$this->orphans->reasons( $coupon, $coupons ),
				$by_coupon[ $coupon->id->value ] ?? array()
			);
		}

		return new Inventory( $entries, $overlaps );
	}

	/**
	 * Index the overlaps by each coupon they involve.
	 *
	 * Built once rather than filtered per row, which would be quadratic all over
	 * again in the one place the index was meant to save.
	 *
	 * @param list<Overlap> $overlaps Every collision found.
	 *
	 * @return array<int, list<Overlap>>
	 */
	private function group_by_coupon( array $overlaps ): array {
		$by_coupon = array();

		foreach ( $overlaps as $overlap ) {
			$by_coupon[ $overlap->one->id->value ][]   = $overlap;
			$by_coupon[ $overlap->other->id->value ][] = $overlap;
		}

		return $by_coupon;
	}
}
