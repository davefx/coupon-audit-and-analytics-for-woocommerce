<?php
/**
 * The free tier's gate.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Licensing;

/**
 * Says yes to the free features and no to everything else (§11).
 *
 * This is the whole of licensing in the first release: no SDK, no external
 * request, no consent notice, nothing for a repository reviewer to object to.
 * The licensed gate arrives once the slug is approved, and replaces this class
 * alone.
 */
final class LocalFeatureGate implements FeatureGateInterface {

	/**
	 * Whether the store may use a feature.
	 *
	 * @param Feature $feature The feature in question.
	 */
	public function allows( Feature $feature ): bool {
		return $feature->is_free();
	}
}
