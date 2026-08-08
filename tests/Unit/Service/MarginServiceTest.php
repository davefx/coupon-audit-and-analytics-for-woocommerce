<?php
/**
 * Margin service unit tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use DFX\CouponAAW\Domain\Coupon\CouponId;
use DFX\CouponAAW\Domain\Profit\CostCoverage;
use DFX\CouponAAW\Domain\Profit\CouponDayStats;
use DFX\CouponAAW\Domain\Profit\Money;
use DFX\CouponAAW\Service\MarginService;
use DFX\CouponAAW\Tests\Fixtures\CouponSnapshotBuilder;
use DFX\CouponAAW\Tests\Fixtures\FrozenClock;
use DFX\CouponAAW\Tests\Fixtures\InMemoryCouponRepository;
use DFX\CouponAAW\Tests\Fixtures\InMemoryCouponStatsRepository;
use PHPUnit\Framework\TestCase;

/**
 * Sums daily aggregates into the figures the margin screen shows.
 *
 * The free window is thirty days, and §11 asks that this be a consequence of
 * the process rather than a filter over a longer answer. It is: nothing outside
 * the window is ever summed.
 */
final class MarginServiceTest extends TestCase {

	/**
	 * Where the daily figures live.
	 *
	 * @var InMemoryCouponStatsRepository
	 */
	private InMemoryCouponStatsRepository $stats;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->stats = new InMemoryCouponStatsRepository();
	}

	/**
	 * Store a day's figures for a coupon.
	 *
	 * @param string $day           The day.
	 * @param int    $coupon_id     The coupon.
	 * @param int    $revenue       Net revenue in minor units.
	 * @param int    $cost          Cost in minor units.
	 * @param int    $covered_lines Lines whose cost is known.
	 * @param int    $total_lines   Lines in total.
	 * @param string $currency      Currency code.
	 */
	private function store(
		string $day,
		int $coupon_id,
		int $revenue,
		int $cost = 0,
		int $covered_lines = 0,
		int $total_lines = 1,
		string $currency = 'EUR'
	): void {
		$date = new DateTimeImmutable( $day, new DateTimeZone( 'UTC' ) );

		$this->stats->replace_day(
			$date,
			array_merge(
				$this->stats->for_day( $date ),
				array(
					new CouponDayStats(
						new CouponId( $coupon_id ),
						$date,
						1,
						new Money( $revenue, $currency ),
						new Money( 100, $currency ),
						new Money( $cost, $currency ),
						$covered_lines,
						$total_lines
					),
				)
			),
			'fake'
		);
	}

	/**
	 * Build the service, with a clock at 2026-07-28.
	 *
	 * @param int|null $window_days How far back to look, or the default.
	 */
	private function service( ?int $window_days = null ): MarginService {
		return new MarginService(
			$this->stats,
			new InMemoryCouponRepository(
				array(
					CouponSnapshotBuilder::make()->with_id( 1 )->with_code( 'alpha' )->build(),
					CouponSnapshotBuilder::make()->with_id( 2 )->with_code( 'beta' )->build(),
				)
			),
			FrozenClock::at( '2026-07-28' ),
			$window_days ?? MarginService::WINDOW_DAYS
		);
	}

	/**
	 * A store with no aggregates has nothing to show, rather than an error.
	 */
	public function test_an_empty_store_has_no_margins(): void {
		$this->assertSame( array(), $this->service()->margins() );
	}

	/**
	 * Days within the window are summed into one figure per coupon.
	 */
	public function test_it_sums_the_days_in_the_window(): void {
		$this->store( '2026-07-28', 1, 5000, 2000, 1, 1 );
		$this->store( '2026-07-27', 1, 3000, 1000, 1, 1 );

		$margins = $this->service()->margins();

		$this->assertCount( 1, $margins );
		$this->assertSame( 'alpha', $margins[0]->code );
		$this->assertSame( 8000, $margins[0]->net_revenue->amount );
		$this->assertSame( 3000, $margins[0]->cost->amount );
		$this->assertSame( 5000, $margins[0]->margin()?->amount );
	}

	/**
	 * The free tier looks back thirty days, and a day outside that is not
	 * summed at all — there is no longer figure being hidden.
	 */
	public function test_the_free_window_is_thirty_days(): void {
		$this->store( '2026-07-28', 1, 5000, 1000, 1, 1 );
		$this->store( '2026-06-01', 1, 90000, 1000, 1, 1 );

		$margins = $this->service()->margins();

		$this->assertSame( 30, $this->service()->window_days() );
		$this->assertSame( 5000, $margins[0]->net_revenue->amount, 'The older day is outside the window.' );
	}

	/**
	 * The last day of the window is included, so the boundary is not off by one.
	 */
	public function test_the_oldest_day_in_the_window_counts(): void {
		$this->store( '2026-06-29', 1, 4200, 1000, 1, 1 );

		$this->assertSame( 4200, $this->service()->margins()[0]->net_revenue->amount );
	}

	/**
	 * A wider window looks back further, through the same code path.
	 *
	 * The window is a number this class is handed, so widening it is not a
	 * separate branch that could rot — it is the same summing over more days.
	 */
	public function test_a_wider_window_sees_more_history(): void {
		$this->store( '2026-07-28', 1, 5000, 1000, 1, 1 );
		$this->store( '2026-06-01', 1, 90000, 1000, 1, 1 );

		$this->assertSame( 365, $this->service( 365 )->window_days() );
		$this->assertSame( 95000, $this->service( 365 )->margins()[0]->net_revenue->amount );
	}

	/**
	 * The default window is thirty days, and the day before it is outside.
	 */
	public function test_the_default_window_is_thirty_days(): void {
		$this->assertSame( 30, $this->service()->window_days() );
	}

	/**
	 * Coverage survives the summing: a period is fully covered only if every
	 * day in it was, and the share is over the period's lines rather than an
	 * average of daily percentages.
	 */
	public function test_coverage_is_taken_over_the_whole_period(): void {
		$this->store( '2026-07-28', 1, 5000, 2000, 1, 1 );
		$this->store( '2026-07-27', 1, 5000, 0, 0, 3 );

		$margin = $this->service()->margins()[0];

		$this->assertSame( CostCoverage::PARTIAL, $margin->coverage() );
		$this->assertSame( 25, $margin->coverage_percentage(), 'One line of four, not an average of 100% and 0%.' );
	}

	/**
	 * With no cost known anywhere in the period there is no margin.
	 */
	public function test_a_period_without_cost_data_has_no_margin(): void {
		$this->store( '2026-07-28', 1, 5000, 0, 0, 2 );

		$margin = $this->service()->margins()[0];

		$this->assertSame( CostCoverage::NONE, $margin->coverage() );
		$this->assertNull( $margin->margin() );
		$this->assertNull( $margin->margin_percentage() );
	}

	/**
	 * The biggest earner comes first: a screen listing coupons by database
	 * order buries the one worth looking at.
	 */
	public function test_coupons_are_ordered_by_revenue(): void {
		$this->store( '2026-07-28', 1, 1000, 100, 1, 1 );
		$this->store( '2026-07-28', 2, 9000, 100, 1, 1 );

		$margins = $this->service()->margins();

		$this->assertSame( 'beta', $margins[0]->code );
		$this->assertSame( 'alpha', $margins[1]->code );
	}

	/**
	 * Two currencies give one line each, never one line adding them together.
	 */
	public function test_currencies_are_kept_apart(): void {
		$this->store( '2026-07-28', 1, 5000, 1000, 1, 1 );
		$this->store( '2026-07-27', 1, 3000, 1000, 1, 1, 'USD' );

		$margins = $this->service()->margins();

		$this->assertCount( 2, $margins );
		$this->assertSame( 'EUR', $margins[0]->currency() );
		$this->assertSame( 'USD', $margins[1]->currency() );
	}

	/**
	 * A coupon deleted since the orders were placed still has figures, and they
	 * are still shown: revenue it earned did not stop having been earned. It has
	 * no code, and the null says so — phrasing that for a reader belongs to the
	 * screen, not to a service that must not know WordPress exists.
	 */
	public function test_a_deleted_coupon_keeps_its_figures(): void {
		$this->store( '2026-07-28', 99, 5000, 1000, 1, 1 );

		$margin = $this->service()->margins()[0];

		$this->assertSame( 5000, $margin->net_revenue->amount );
		$this->assertNull( $margin->code );
		$this->assertSame( 99, $margin->coupon_id->value );
	}

	/**
	 * Margin as a share of revenue, which is the figure a shop owner compares
	 * one coupon against another with.
	 */
	public function test_it_reports_margin_as_a_share_of_revenue(): void {
		$this->store( '2026-07-28', 1, 10000, 4000, 1, 1 );

		$this->assertSame( 60.0, $this->service()->margins()[0]->margin_percentage() );
	}
}
