<?php
/**
 * How badly two coupons collide.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Overlap;

use DFX\CouponAAW\Domain\EnumType;

/**
 * The seriousness of an overlap (§8.3).
 *
 * The grading is by how likely the collision is to actually happen, not by how
 * much money is at stake — this half of the plugin knows nothing about money.
 *
 * An enum in every respect the code relies on, spelt as a class so it runs on
 * PHP 7.4; see {@see EnumType}.
 */
final class OverlapSeverity {

	use EnumType;

	/**
	 * Both coupons apply to the same product, at the same time, without anyone
	 * having to enter a code. This collision does not wait to be discovered.
	 */
	public static function HIGH(): self {
		return self::of( 'HIGH' );
	}

	/**
	 * The coupons cover the same products over the same period. A customer who
	 * finds both can use both.
	 */
	public static function MEDIUM(): self {
		return self::of( 'MEDIUM' );
	}

	/**
	 * The coupons cover the same products but never at the same time. They
	 * collide on paper and never in a basket.
	 */
	public static function LOW(): self {
		return self::of( 'LOW' );
	}

	/**
	 * Every case, as name to backing value: the single source of both.
	 *
	 * @return array<string, string>
	 */
	protected static function map(): array {
		return array(
			'HIGH'   => 'high',
			'MEDIUM' => 'medium',
			'LOW'    => 'low',
		);
	}

	/**
	 * How serious this is, as a number, so the worst of several can be picked.
	 *
	 * The cases have no inherent order and relying on declaration order would
	 * make reordering them a silent behaviour change.
	 */
	public function rank(): int {
		if ( self::HIGH() === $this ) {
			return 3;
		}

		if ( self::MEDIUM() === $this ) {
			return 2;
		}

		return 1;
	}
}
