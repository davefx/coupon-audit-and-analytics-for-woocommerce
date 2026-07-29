<?php
/**
 * How badly two coupons collide.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Overlap;

/**
 * The seriousness of an overlap (§8.3).
 *
 * The grading is by how likely the collision is to actually happen, not by how
 * much money is at stake — this half of the plugin knows nothing about money.
 */
enum OverlapSeverity: string {

	/**
	 * Both coupons apply to the same product, at the same time, without anyone
	 * having to enter a code. This collision does not wait to be discovered.
	 */
	case HIGH = 'high';

	/**
	 * The coupons cover the same products over the same period. A customer who
	 * finds both can use both.
	 */
	case MEDIUM = 'medium';

	/**
	 * The coupons cover the same products but never at the same time. They
	 * collide on paper and never in a basket.
	 */
	case LOW = 'low';

	/**
	 * How serious this is, as a number, so the worst of several can be picked.
	 *
	 * Enum cases have no inherent order and relying on declaration order would
	 * make reordering them a silent behaviour change.
	 */
	public function rank(): int {
		return match ( $this ) {
			self::HIGH   => 3,
			self::MEDIUM => 2,
			self::LOW    => 1,
		};
	}
}
