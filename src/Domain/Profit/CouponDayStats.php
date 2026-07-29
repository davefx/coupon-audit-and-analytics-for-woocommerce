<?php
/**
 * One coupon's figures for one day.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Profit;

use DateTimeImmutable;
use DFX\CouponAAW\Domain\Coupon\CouponId;
use InvalidArgumentException;

/**
 * A row of the aggregates table (§6.2): one coupon, one day, one currency.
 *
 * Every number the paid half of the product shows comes from here, so what this
 * refuses to compute matters as much as what it does.
 */
final class CouponDayStats {

	/**
	 * Constructor.
	 *
	 * @param CouponId          $coupon_id     The coupon.
	 * @param DateTimeImmutable $date          The day, in the store's timezone.
	 * @param int               $orders        Orders in which the coupon was used.
	 * @param Money             $net_revenue   Revenue after discount, excluding tax and shipping.
	 * @param Money             $discount      Discount the coupon gave.
	 * @param Money             $cost          Cost of the goods sold, over the covered lines.
	 * @param int               $covered_lines Lines whose cost was known.
	 * @param int               $total_lines   Lines in total.
	 *
	 * @throws InvalidArgumentException When the figures could not describe a real day.
	 */
	public function __construct(
		public readonly CouponId $coupon_id,
		public readonly DateTimeImmutable $date,
		public readonly int $orders,
		public readonly Money $net_revenue,
		public readonly Money $discount,
		public readonly Money $cost,
		public readonly int $covered_lines,
		public readonly int $total_lines
	) {
		if ( $net_revenue->currency !== $discount->currency || $net_revenue->currency !== $cost->currency ) {
			throw new InvalidArgumentException(
				'A day\'s figures must all be in one currency; currencies are aggregated separately.'
			);
		}

		if ( $covered_lines < 0 || $total_lines < 0 ) {
			throw new InvalidArgumentException( 'Line counts cannot be negative.' );
		}

		if ( $covered_lines > $total_lines ) {
			throw new InvalidArgumentException( 'More lines have a cost than exist.' );
		}
	}

	/**
	 * The currency every figure in this row is in.
	 */
	public function currency(): string {
		return $this->net_revenue->currency;
	}

	/**
	 * How much of this row is backed by real cost data.
	 */
	public function coverage(): CostCoverage {
		return CostCoverage::from_counts( $this->covered_lines, $this->total_lines );
	}

	/**
	 * The covered share, as a whole percentage, for the notice §6.3 requires.
	 */
	public function coverage_percentage(): int {
		if ( $this->total_lines < 1 ) {
			return 0;
		}

		return (int) round( $this->covered_lines / $this->total_lines * 100 );
	}

	/**
	 * Revenue before the coupon was applied.
	 *
	 * Matches WooCommerce's own definition, which reaches gross sales by adding
	 * the discount back to net revenue.
	 */
	public function gross_sales(): Money {
		return $this->net_revenue->plus( $this->discount );
	}

	/**
	 * Gross margin, or null when no cost is known.
	 *
	 * Net revenue less cost of goods. §8.5 states the formula as net revenue
	 * less cost less discount, which subtracts the discount twice: WooCommerce
	 * reaches gross sales by *adding* the discount back to net revenue, which is
	 * only coherent if net revenue is already net of it. Following §8.5 literally
	 * would understate every margin by exactly the discount — on the single
	 * number this product exists to report.
	 */
	public function margin(): ?Money {
		if ( ! $this->coverage()->allows_margin() ) {
			return null;
		}

		return $this->net_revenue->minus( $this->cost );
	}
}
