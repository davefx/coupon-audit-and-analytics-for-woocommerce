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
     * @var list<CouponMargin>
     * @readonly
     */
    public array $margins;
    /**
     * @var int
     * @readonly
     */
    public int $total;
    /**
     * @var int
     * @readonly
     */
    public int $with_cost = 0;
    /**
	 * Constructor.
	 *
	 * @param list<CouponMargin> $margins   The rows of the page asked for.
	 * @param int                $total     How many lines the window comes to.
	 * @param int                $with_cost How many of those lines have any cost recorded.
	 */
	public function __construct(array $margins, int $total, int $with_cost = 0)
    {
        $this->margins = $margins;
        $this->total = $total;
        $this->with_cost = $with_cost;
    }

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
