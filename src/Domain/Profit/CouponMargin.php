<?php
/**
 * One coupon's figures over a period.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Profit;

use DFX\CouponAAW\Domain\Coupon\CouponId;
use InvalidArgumentException;

/**
 * A coupon's totals over a window, in one currency.
 *
 * Built by summing the daily rows, which is why coverage survives the addition:
 * a period is fully covered only if every day in it was, and the percentage is
 * over the period's lines rather than an average of daily percentages — a day
 * with one line would otherwise weigh as much as a day with four hundred.
 */
final class CouponMargin {

	/**
     * @var CouponId
     * @readonly
     */
    public CouponId $coupon_id;
    /**
     * @var string|null
     * @readonly
     */
    public ?string $code;
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
	 * @param CouponId    $coupon_id     The coupon.
	 * @param string|null $code       Its code, or null if the coupon has since been deleted.
	 * @param int         $orders        Orders in which it was used.
	 * @param Money       $net_revenue   Revenue after discount, excluding tax and shipping.
	 * @param Money       $discount      What it gave away.
	 * @param Money       $cost          Cost of the goods sold, over the covered lines.
	 * @param int         $covered_lines Lines whose cost was known.
	 * @param int         $total_lines   Lines in total.
	 *
	 * @throws InvalidArgumentException When the figures are not all in one currency.
	 */
	public function __construct(
		CouponId $coupon_id,
		?string $code,
		int $orders,
		Money $net_revenue,
		Money $discount,
		Money $cost,
		int $covered_lines,
		int $total_lines
	) {
		$this->coupon_id = $coupon_id;
        $this->code = $code;
        $this->orders = $orders;
        $this->net_revenue = $net_revenue;
        $this->discount = $discount;
        $this->cost = $cost;
        $this->covered_lines = $covered_lines;
        $this->total_lines = $total_lines;
        if ( $net_revenue->currency !== $discount->currency || $net_revenue->currency !== $cost->currency ) {
			throw new InvalidArgumentException( 'A coupon\'s figures must all be in one currency.' );
		}
	}

	/**
	 * The currency every figure is in.
	 */
	public function currency(): string {
		return $this->net_revenue->currency;
	}

	/**
	 * How much of this is backed by real cost data.
	 */
	public function coverage(): CostCoverage {
		return CostCoverage::from_counts( $this->covered_lines, $this->total_lines );
	}

	/**
	 * The covered share, as a whole percentage.
	 */
	public function coverage_percentage(): int {
		if ( $this->total_lines < 1 ) {
			return 0;
		}

		return (int) round( $this->covered_lines / $this->total_lines * 100 );
	}

	/**
	 * Gross margin, or null when no cost is known.
	 */
	public function margin(): ?Money {
		if ( ! $this->coverage()->allows_margin() ) {
			return null;
		}

		return $this->net_revenue->minus( $this->cost );
	}

	/**
	 * Margin as a percentage of revenue, or null when there is no margin or no
	 * revenue to take it against.
	 */
	public function margin_percentage(): ?float {
		$margin = $this->margin();

		if ( null === $margin || $this->net_revenue->is_zero() ) {
			return null;
		}

		return round( $margin->amount / $this->net_revenue->amount * 100, 1 );
	}
}
