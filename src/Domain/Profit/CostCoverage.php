<?php
/**
 * How much of a figure is backed by real cost data.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Profit;

/**
 * The central concept behind graceful degradation (§6.3).
 *
 * A margin computed over incomplete data is never presented as if it were
 * reliable, because a wrong number in a financial dashboard destroys trust far
 * faster than a missing number builds it.
 */
enum CostCoverage: string {

	/**
	 * Every line had a known cost. The margin stands without caveat.
	 */
	case FULL = 'full';

	/**
	 * Some lines had a known cost. The margin is shown with the covered share
	 * stated alongside it.
	 */
	case PARTIAL = 'partial';

	/**
	 * No line had a known cost. No margin is shown at all.
	 */
	case NONE = 'none';

	/**
	 * Derive coverage from how many lines had a cost.
	 *
	 * @param int $covered Lines whose cost was known.
	 * @param int $total   Lines in total.
	 */
	public static function from_counts( int $covered, int $total ): self {
		if ( $total < 1 || $covered < 1 ) {
			return self::NONE;
		}

		return $covered >= $total ? self::FULL : self::PARTIAL;
	}

	/**
	 * Whether a margin may be shown at all.
	 */
	public function allows_margin(): bool {
		return self::NONE !== $this;
	}
}
