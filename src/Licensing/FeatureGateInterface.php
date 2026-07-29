<?php
/**
 * Feature availability contract.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Licensing;

/**
 * Answers whether the store may use a given feature (§11).
 *
 * Business code asks this and never a licensing SDK, so both the free and the
 * licensed paths can be tested without loading one. Swapping the free
 * implementation for a licensed one touches this file's implementers and
 * nothing else.
 */
interface FeatureGateInterface {

	/**
	 * Whether the store may use a feature.
	 *
	 * @param Feature $feature The feature in question.
	 */
	public function allows( Feature $feature ): bool;
}
