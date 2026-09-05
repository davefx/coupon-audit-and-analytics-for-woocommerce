<?php
/**
 * One coupon's figures over a window.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Profit;

use DFX\CouponAAW\Domain\Coupon\CouponId;

/**
 * What a coupon did over a span of days, in one currency.
 *
 * `CouponDayStats` is a day; this is a window of them added up. The distinction
 * matters because the adding up happens in the database: the margin screen used
 * to read every day of every coupon and total them in PHP, which at a shop with
 * twenty-six thousand coupons is hundreds of thousands of objects built in order
 * to be added to a running total and discarded.
 *
 * It carries no code and no margin. The code belongs to the coupon, which may
 * since have been deleted, and the margin is arithmetic the domain does; both
 * are added by `MarginService` on the way to a `CouponMargin`.
 */
final class CouponTotals {

	/**
     * @var CouponId
     * @readonly
     */
    public CouponId $coupon_id;
    /**
     * @var int
     * @readonly
     */
    public int $orders;
    /**
     * @var Money
     * @readonly
     */
    public Money $net_revenue;
    /**
     * @var Money
     * @readonly
     */
    public Money $discount;
    /**
     * @var Money
     * @readonly
     */
    public Money $cost;
    /**
     * @var int
     * @readonly
     */
    public int $covered_lines;
    /**
     * @var int
     * @readonly
     */
    public int $total_lines;
    /**
	 * Constructor.
	 *
	 * @param CouponId $coupon_id     Which coupon.
	 * @param int      $orders        Orders it was used on.
	 * @param Money    $net_revenue   Revenue after the discount.
	 * @param Money    $discount      What it gave away.
	 * @param Money    $cost          Cost of what was sold, where cost is known.
	 * @param int      $covered_lines Lines whose cost was known.
	 * @param int      $total_lines   Lines altogether.
	 */
	public function __construct(CouponId $coupon_id, int $orders, Money $net_revenue, Money $discount, Money $cost, int $covered_lines, int $total_lines)
    {
        $this->coupon_id = $coupon_id;
        $this->orders = $orders;
        $this->net_revenue = $net_revenue;
        $this->discount = $discount;
        $this->cost = $cost;
        $this->covered_lines = $covered_lines;
        $this->total_lines = $total_lines;
    }

	/**
	 * The currency these figures are in.
	 */
	public function currency(): string {
		return $this->net_revenue->currency;
	}
}
