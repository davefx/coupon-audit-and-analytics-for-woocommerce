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
	 * Constructor.
	 *
	 * @param CouponRepositoryInterface $coupons Source of coupons.
	 * @param StatusResolver            $status  Resolves each coupon's status.
	 * @param OrphanDetector            $orphans Judges each coupon against the rest.
	 */
	public function __construct(
		private readonly CouponRepositoryInterface $coupons,
		private readonly StatusResolver $status,
		private readonly OrphanDetector $orphans
	) {}

	/**
	 * Read every coupon and judge it.
	 *
	 * The whole inventory is read in one go, which is not merely an
	 * optimisation: the dead-campaign rule judges a coupon against every other
	 * coupon, so the full set has to be in hand before any single entry can be
	 * built.
	 */
	public function build(): Inventory {
		$coupons = $this->coupons->all();
		$entries = array();

		foreach ( $coupons as $coupon ) {
			$entries[] = new InventoryEntry(
				$coupon,
				$this->status->resolve( $coupon ),
				$this->orphans->reasons( $coupon, $coupons )
			);
		}

		return new Inventory( $entries );
	}
}
