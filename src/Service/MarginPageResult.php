<?php
/**
 * One page of the margin screen.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Service;

use DFX\CouponAAW\Domain\Profit\CouponMargin;

/**
 * The rows of one page of margins, and how many rows there are altogether.
 *
 * Named for the result rather than the screen because `MarginPage` is already
 * the screen, in `Admin\`.
 */
final class MarginPageResult {

	/**
	 * Constructor.
	 *
	 * @param list<CouponMargin> $margins   The rows of the page asked for.
	 * @param int                $total     How many lines the window comes to.
	 * @param int                $with_cost How many of those lines have any cost recorded.
	 */
	public function __construct(
		public readonly array $margins,
		public readonly int $total,
		public readonly int $with_cost = 0
	) {}

	/**
	 * Whether every line in the window has some cost behind it.
	 *
	 * A fact about the window, not about the page. The screen explains itself
	 * with it, and an explanation that changed as you turned the page would be
	 * describing the twenty rows rather than the shop.
	 */
	public function cost_is_complete(): bool {
		return $this->with_cost >= $this->total;
	}
}
