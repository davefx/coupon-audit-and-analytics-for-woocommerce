<?php
/**
 * Coupon margin tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Domain\Profit;

use DFX\CouponAAW\Domain\Coupon\CouponId;
use DFX\CouponAAW\Domain\Profit\CostCoverage;
use DFX\CouponAAW\Domain\Profit\CouponMargin;
use DFX\CouponAAW\Domain\Profit\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * What one coupon earned over a window.
 *
 * The figures here end up on screen next to a currency symbol, so the rules
 * about when a number may not be shown at all matter as much as the arithmetic.
 */
final class CouponMarginTest extends TestCase {

	/**
	 * Build a margin, defaulting to a fully covered one.
	 *
	 * @param int         $net           Net revenue in minor units.
	 * @param int         $cost          Cost in minor units.
	 * @param int         $covered_lines Lines whose cost was known.
	 * @param int         $total_lines   Lines in total.
	 * @param string|null $code          The coupon's code.
	 */
	private function margin(
		int $net = 10000,
		int $cost = 4000,
		int $covered_lines = 10,
		int $total_lines = 10,
		?string $code = 'summer'
	): CouponMargin {
		return new CouponMargin(
			new CouponId( 1 ),
			$code,
			5,
			new Money( $net, 'EUR' ),
			new Money( 1500, 'EUR' ),
			new Money( $cost, 'EUR' ),
			$covered_lines,
			$total_lines
		);
	}

	/**
	 * Figures in different currencies are not a coupon's figures, they are two
	 * coupons' figures added together by accident.
	 */
	public function test_the_figures_must_all_be_in_one_currency(): void {
		$this->expectException( InvalidArgumentException::class );

		new CouponMargin(
			new CouponId( 1 ),
			'summer',
			1,
			new Money( 10000, 'EUR' ),
			new Money( 1500, 'USD' ),
			new Money( 4000, 'EUR' ),
			1,
			1
		);
	}

	/**
	 * A cost in another currency is caught too, not only the discount.
	 */
	public function test_a_stray_cost_currency_is_caught(): void {
		$this->expectException( InvalidArgumentException::class );

		new CouponMargin(
			new CouponId( 1 ),
			'summer',
			1,
			new Money( 10000, 'EUR' ),
			new Money( 1500, 'EUR' ),
			new Money( 4000, 'GBP' ),
			1,
			1
		);
	}

	/**
	 * The currency is the one every figure shares.
	 */
	public function test_it_reports_its_currency(): void {
		$this->assertSame( 'EUR', $this->margin()->currency() );
	}

	/**
	 * Margin is revenue less cost.
	 */
	public function test_margin_is_revenue_less_cost(): void {
		$this->assertSame( 6000, $this->margin( 10000, 4000 )->margin()?->amount );
	}

	/**
	 * A coupon that sold at a loss reports the loss rather than clamping at zero.
	 * A negative margin is the finding, not an error.
	 */
	public function test_a_loss_is_reported_as_a_loss(): void {
		$this->assertSame( -1000, $this->margin( 3000, 4000 )->margin()?->amount );
	}

	/**
	 * With no cost known for anything, there is no margin to report. §6.3 is
	 * explicit that a blank beats a confident wrong number.
	 */
	public function test_no_cost_data_means_no_margin(): void {
		$margin = $this->margin( covered_lines: 0, total_lines: 10 );

		$this->assertSame( CostCoverage::NONE, $margin->coverage() );
		$this->assertNull( $margin->margin() );
		$this->assertNull( $margin->margin_percentage() );
	}

	/**
	 * The covered share is taken over lines, and rounds to a whole percentage.
	 */
	public function test_coverage_is_a_whole_percentage_of_the_lines(): void {
		$this->assertSame( 33, $this->margin( covered_lines: 1, total_lines: 3 )->coverage_percentage() );
	}

	/**
	 * A window with no lines at all is nought per cent covered rather than a
	 * division by zero.
	 */
	public function test_coverage_of_nothing_is_zero_rather_than_an_error(): void {
		$this->assertSame( 0, $this->margin( covered_lines: 0, total_lines: 0 )->coverage_percentage() );
	}

	/**
	 * Margin as a share of revenue, to one place.
	 */
	public function test_margin_percentage_is_taken_against_revenue(): void {
		$this->assertSame( 60.0, $this->margin( 10000, 4000 )->margin_percentage() );
	}

	/**
	 * A coupon that took no money has no margin percentage: the division has no
	 * meaning, and reporting nought per cent would read as "sold at cost".
	 */
	public function test_no_revenue_means_no_margin_percentage(): void {
		$margin = $this->margin( 0, 0 );

		$this->assertSame( 0, $margin->margin()?->amount );
		$this->assertNull( $margin->margin_percentage() );
	}

	/**
	 * A coupon deleted since the window it was used in still has figures, and
	 * they still have to be reportable. The code is simply not known.
	 */
	public function test_a_deleted_coupon_keeps_its_figures(): void {
		$margin = $this->margin( code: null );

		$this->assertNull( $margin->code );
		$this->assertSame( 6000, $margin->margin()?->amount );
	}
}
