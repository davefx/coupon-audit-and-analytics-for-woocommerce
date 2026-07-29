<?php
/**
 * Coupon day statistics unit tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Domain\Profit;

use DateTimeImmutable;
use DateTimeZone;
use DFX\CouponAAW\Domain\Coupon\CouponId;
use DFX\CouponAAW\Domain\Profit\CostCoverage;
use DFX\CouponAAW\Domain\Profit\CouponDayStats;
use DFX\CouponAAW\Domain\Profit\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * One coupon's figures for one day, in one currency (§6.2).
 *
 * This is the row the aggregates table holds and the margin screen reads. Every
 * number the paid half of the product shows comes from here, so what it refuses
 * to compute matters as much as what it does.
 */
final class CouponDayStatsTest extends TestCase {

	/**
	 * Build a day's figures, varying one thing at a time.
	 *
	 * @param int $revenue       Net revenue in minor units.
	 * @param int $discount      Discount in minor units.
	 * @param int $cost          Cost of goods in minor units.
	 * @param int $covered_lines Lines whose cost is known.
	 * @param int $total_lines   Lines in total.
	 */
	private function stats(
		int $revenue = 10000,
		int $discount = 1000,
		int $cost = 4000,
		int $covered_lines = 4,
		int $total_lines = 4
	): CouponDayStats {
		return new CouponDayStats(
			new CouponId( 1 ),
			new DateTimeImmutable( '2026-07-01', new DateTimeZone( 'UTC' ) ),
			3,
			new Money( $revenue, 'EUR' ),
			new Money( $discount, 'EUR' ),
			new Money( $cost, 'EUR' ),
			$covered_lines,
			$total_lines
		);
	}

	/**
	 * The values survive the constructor.
	 */
	public function test_it_carries_its_figures(): void {
		$stats = $this->stats();

		$this->assertSame( 1, $stats->coupon_id->value );
		$this->assertSame( '2026-07-01', $stats->date->format( 'Y-m-d' ) );
		$this->assertSame( 3, $stats->orders );
		$this->assertSame( 10000, $stats->net_revenue->amount );
		$this->assertSame( 'EUR', $stats->currency() );
	}

	/**
	 * Margin is net revenue less cost of goods, and nothing else.
	 *
	 * §8.5 gives the formula as net revenue less cost less discount, which
	 * subtracts the discount twice. WooCommerce's own reports settle it: they
	 * compute gross sales by *adding* the discount back to net revenue
	 * (`total_sales - tax - shipping + discount_amount`), which is only
	 * coherent if net revenue is already net of the discount. Subtracting it
	 * again would understate margin by exactly the discount — on the one number
	 * this product exists to report.
	 */
	public function test_margin_is_revenue_less_cost(): void {
		$margin = $this->stats( 10000, 1000, 4000 )->margin();

		$this->assertNotNull( $margin );
		$this->assertSame( 6000, $margin->amount );
	}

	/**
	 * The same figures, expressed the other way round, agree. Gross sales less
	 * discount less cost is the same number as net revenue less cost.
	 */
	public function test_the_two_ways_of_stating_margin_agree(): void {
		$stats = $this->stats( 10000, 1000, 4000 );

		$gross_route = $stats->gross_sales()->minus( $stats->discount )->minus( $stats->cost );

		$this->assertTrue( $stats->margin()?->equals( $gross_route ) ?? false );
	}

	/**
	 * Gross sales adds the discount back, matching WooCommerce's definition.
	 */
	public function test_gross_sales_adds_the_discount_back(): void {
		$this->assertSame( 11000, $this->stats( 10000, 1000 )->gross_sales()->amount );
	}

	/**
	 * A margin can be negative, and saying so is the entire point of the
	 * product.
	 */
	public function test_margin_can_be_negative(): void {
		$margin = $this->stats( 1000, 500, 4000 )->margin();

		$this->assertNotNull( $margin );
		$this->assertTrue( $margin->is_negative() );
	}

	/**
	 * With every line's cost known, the figure carries no caveat.
	 */
	public function test_full_coverage_when_every_line_has_a_cost(): void {
		$stats = $this->stats( covered_lines: 4, total_lines: 4 );

		$this->assertSame( CostCoverage::FULL, $stats->coverage() );
		$this->assertSame( 100, $stats->coverage_percentage() );
	}

	/**
	 * With some lines missing a cost the margin is still shown, but the screen
	 * has to say how much of it is real (§6.3).
	 */
	public function test_partial_coverage_when_some_lines_have_a_cost(): void {
		$stats = $this->stats( covered_lines: 3, total_lines: 4 );

		$this->assertSame( CostCoverage::PARTIAL, $stats->coverage() );
		$this->assertSame( 75, $stats->coverage_percentage() );
		$this->assertNotNull( $stats->margin() );
	}

	/**
	 * With no line's cost known there is no margin to show. §6.3 is explicit
	 * that a wrong number in a financial dashboard destroys trust faster than a
	 * missing number builds it, so this returns nothing rather than revenue
	 * dressed up as profit.
	 */
	public function test_no_margin_without_any_cost_data(): void {
		$stats = $this->stats( covered_lines: 0, total_lines: 4 );

		$this->assertSame( CostCoverage::NONE, $stats->coverage() );
		$this->assertSame( 0, $stats->coverage_percentage() );
		$this->assertNull( $stats->margin() );
	}

	/**
	 * A day with no lines at all has nothing to cover, and still no margin.
	 */
	public function test_a_day_with_no_lines_has_no_coverage(): void {
		$stats = $this->stats( covered_lines: 0, total_lines: 0 );

		$this->assertSame( CostCoverage::NONE, $stats->coverage() );
		$this->assertNull( $stats->margin() );
	}

	/**
	 * Every amount in a row is in one currency; §8.5 aggregates currencies
	 * separately and never sums them, so a row mixing them is a bug in whatever
	 * built it.
	 */
	public function test_it_rejects_figures_in_different_currencies(): void {
		$this->expectException( InvalidArgumentException::class );

		new CouponDayStats(
			new CouponId( 1 ),
			new DateTimeImmutable( '2026-07-01', new DateTimeZone( 'UTC' ) ),
			1,
			new Money( 10000, 'EUR' ),
			new Money( 1000, 'USD' ),
			new Money( 4000, 'EUR' ),
			1,
			1
		);
	}

	/**
	 * More covered lines than lines is impossible and worth refusing loudly.
	 */
	public function test_it_rejects_more_covered_lines_than_lines(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->stats( covered_lines: 5, total_lines: 4 );
	}

	/**
	 * Negative counts are impossible too.
	 */
	public function test_it_rejects_negative_counts(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->stats( covered_lines: -1, total_lines: 4 );
	}
}
