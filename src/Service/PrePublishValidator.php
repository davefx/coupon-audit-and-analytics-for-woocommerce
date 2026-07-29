<?php
/**
 * Pre-publish validation.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Service;

use DFX\CouponAAW\Domain\Coupon\CouponSnapshot;
use DFX\CouponAAW\Domain\Overlap\Overlap;
use DFX\CouponAAW\Domain\Overlap\OverlapDetector;
use DFX\CouponAAW\Domain\Overlap\OverlapSeverity;
use DFX\CouponAAW\Repository\CouponRepositoryInterface;

/**
 * Checks a coupon while it is in front of the person editing it (§9).
 *
 * The whole feature turns on one product rule: warnings never block a save.
 * They inform, and the user proceeds if they want to — an analytics plugin that
 * prevents work gets uninstalled the first Tuesday. Accordingly this class
 * returns a list and has no way of expressing a veto; there is nothing here a
 * caller could mistake for one.
 */
final class PrePublishValidator {

	/**
	 * Constructor.
	 *
	 * @param CouponRepositoryInterface $coupons  The coupons already in the store.
	 * @param OverlapDetector           $overlaps Finds colliding pairs.
	 */
	public function __construct(
		private readonly CouponRepositoryInterface $coupons,
		private readonly OverlapDetector $overlaps
	) {}

	/**
	 * Everything worth saying about this coupon, in a stable order.
	 *
	 * @param CouponSnapshot $coupon The coupon being edited.
	 *
	 * @return list<PrePublishWarning>
	 */
	public function validate( CouponSnapshot $coupon ): array {
		$warnings = array();

		if ( null === $coupon->expires_at ) {
			$warnings[] = new PrePublishWarning( PrePublishWarningType::NO_EXPIRY_DATE );
		}

		if ( null === $coupon->usage_limit ) {
			$warnings[] = new PrePublishWarning( PrePublishWarningType::NO_USAGE_LIMIT );
		}

		$collisions = $this->collisions( $coupon );

		if ( array() !== $collisions ) {
			$warnings[] = new PrePublishWarning(
				PrePublishWarningType::OVERLAPS_EXISTING,
				array_map(
					static fn ( Overlap $overlap ): CouponSnapshot => $overlap->counterpart( $coupon->id ),
					$collisions
				),
				$this->worst( $collisions )
			);
		}

		return $warnings;
	}

	/**
	 * Every collision this coupon takes part in.
	 *
	 * The coupon under edit is folded into the store's own list rather than
	 * compared against it, so that a coupon already saved is not compared with
	 * itself and one not yet saved is still compared with everything else.
	 *
	 * Past the point where comparing every coupon is affordable the check is
	 * skipped, exactly as the inventory screen skips it. The cheap checks above
	 * still run: a large store is precisely where a forgotten expiry hides.
	 *
	 * @param CouponSnapshot $coupon The coupon being edited.
	 *
	 * @return list<Overlap>
	 */
	private function collisions( CouponSnapshot $coupon ): array {
		$others = array_values(
			array_filter(
				$this->coupons->all(),
				static fn ( CouponSnapshot $stored ): bool => ! $stored->id->equals( $coupon->id )
			)
		);

		$candidates = array_merge( $others, array( $coupon ) );

		if ( count( $candidates ) > OverlapDetector::SYNCHRONOUS_LIMIT ) {
			return array();
		}

		return array_values(
			array_filter(
				$this->overlaps->detect( $candidates ),
				static fn ( Overlap $overlap ): bool => $overlap->involves( $coupon->id )
			)
		);
	}

	/**
	 * The most serious of several collisions.
	 *
	 * @param list<Overlap> $collisions The collisions found.
	 */
	private function worst( array $collisions ): OverlapSeverity {
		$worst = $collisions[0]->severity;

		foreach ( $collisions as $collision ) {
			if ( $collision->severity->rank() > $worst->rank() ) {
				$worst = $collision->severity;
			}
		}

		return $worst;
	}
}
