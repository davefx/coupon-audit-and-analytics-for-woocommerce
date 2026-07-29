<?php
/**
 * Inventory orchestration.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Service;

use DFX\CouponAAW\Catalog\CatalogRepositoryInterface;
use DFX\CouponAAW\Domain\Coupon\ConfigurationAuditor;
use DFX\CouponAAW\Domain\Coupon\OrphanDetector;
use DFX\CouponAAW\Domain\Coupon\StatusResolver;
use DFX\CouponAAW\Domain\Coupon\CouponSnapshot;
use DFX\CouponAAW\Domain\Overlap\Overlap;
use DFX\CouponAAW\Domain\Profit\Money;
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
	 * Constructor.
	 *
	 * @param CouponRepositoryInterface  $coupons  Source of coupons.
	 * @param StatusResolver             $status   Resolves each coupon's status.
	 * @param OrphanDetector             $orphans  Judges each coupon against the rest.
	 * @param OverlapDetector            $overlaps Finds colliding pairs.
	 * @param ConfigurationAuditor       $auditor  Checks each coupon's own terms.
	 * @param CatalogRepositoryInterface $catalog  Supplies the cheapest reachable price.
	 */
	public function __construct(
		private readonly CouponRepositoryInterface $coupons,
		private readonly StatusResolver $status,
		private readonly OrphanDetector $orphans,
		private readonly OverlapDetector $overlaps,
		private readonly ConfigurationAuditor $auditor,
		private readonly CatalogRepositoryInterface $catalog
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

		$overlaps = count( $coupons ) > OverlapDetector::SYNCHRONOUS_LIMIT
			? null
			: $this->overlaps->detect( $coupons );

		$by_coupon = $this->group_by_coupon( $overlaps ?? array() );
		$entries   = array();

		foreach ( $coupons as $coupon ) {
			$entries[] = new InventoryEntry(
				$coupon,
				$this->status->resolve( $coupon ),
				$this->orphans->reasons( $coupon, $coupons ),
				$by_coupon[ $coupon->id->value ] ?? array(),
				$this->auditor->issues( $coupon, $this->cheapest_for( $coupon ) )
			);
		}

		return new Inventory( $entries, $overlaps );
	}

	/**
	 * The cheapest thing a coupon can be applied to, asked once per coupon and
	 * only where the answer can change the result.
	 *
	 * A percentage discount cannot exceed what it is applied to, so there is
	 * nothing the catalogue could say that would matter — and skipping the query
	 * for the commonest kind of coupon keeps a large inventory affordable.
	 *
	 * @param CouponSnapshot $coupon The coupon.
	 */
	private function cheapest_for( CouponSnapshot $coupon ): ?Money {
		if ( ! $coupon->terms->amount->is_fixed() ) {
			return null;
		}

		return $this->catalog->cheapest_in_scope( $coupon->scope );
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
