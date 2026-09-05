<?php
/**
 * How much of a figure is backed by real cost data.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Profit;

use DFX\CouponAAW\Domain\EnumType;

/**
 * The central concept behind graceful degradation (§6.3).
 *
 * A margin computed over incomplete data is never presented as if it were
 * reliable, because a wrong number in a financial dashboard destroys trust far
 * faster than a missing number builds it.
 *
 * An enum in every respect the code relies on, spelt as a class so it runs on
 * PHP 7.4; see {@see EnumType}.
 */
final class CostCoverage {

	use EnumType;

	/**
	 * Every line had a known cost. The margin stands without caveat.
	 */
	public static function FULL(): self {
		return self::of( 'FULL' );
	}

	/**
	 * Some lines had a known cost. The margin is shown with the covered share
	 * stated alongside it.
	 */
	public static function PARTIAL(): self {
		return self::of( 'PARTIAL' );
	}

	/**
	 * No line had a known cost. No margin is shown at all.
	 */
	public static function NONE(): self {
		return self::of( 'NONE' );
	}

	/**
	 * Every case, as name to backing value: the single source of both.
	 *
	 * @return array<string, string>
	 */
	protected static function map(): array {
		return array(
			'FULL'    => 'full',
			'PARTIAL' => 'partial',
			'NONE'    => 'none',
		);
	}

	/**
	 * Derive coverage from how many lines had a cost.
	 *
	 * @param int $covered Lines whose cost was known.
	 * @param int $total   Lines in total.
	 */
	public static function from_counts( int $covered, int $total ): self {
		if ( $total < 1 || $covered < 1 ) {
			return self::NONE();
		}

		return $covered >= $total ? self::FULL() : self::PARTIAL();
	}

	/**
	 * Whether a margin may be shown at all.
	 */
	public function allows_margin(): bool {
		return self::NONE() !== $this;
	}
}
