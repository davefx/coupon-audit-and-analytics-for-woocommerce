<?php
/**
 * Aggregation unit tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use DFX\CouponAAW\Cost\CostSourceRegistry;
use DFX\CouponAAW\Domain\Profit\CostCoverage;
use DFX\CouponAAW\Domain\Profit\Money;
use DFX\CouponAAW\Domain\Profit\OrderSnapshot;
use DFX\CouponAAW\Service\AggregationService;
use DFX\CouponAAW\Tests\Fixtures\FakeCostSource;
use DFX\CouponAAW\Tests\Fixtures\InMemoryCouponStatsRepository;
use DFX\CouponAAW\Tests\Fixtures\InMemoryOrderStatsRepository;
use PHPUnit\Framework\TestCase;

/**
 * Turns orders into the rows the margin screen reads (§6.2).
 *
 * A day is always recomputed whole and its previous rows discarded, which is
 * what makes the job safe to re-run: refunds, edits and a backfill overlapping
 * work already done all resolve to the same figures instead of piling up.
 */
final class AggregationServiceTest extends TestCase {

	/**
	 * The day everything in these tests happens on.
	 */
	private function day(): DateTimeImmutable {
		return new DateTimeImmutable( '2026-07-01', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Build an order.
	 *
	 * @param int             $id        Order ID.
	 * @param int             $revenue   Net revenue in minor units.
	 * @param array<int, int> $discounts Discount per coupon ID, in minor units.
	 * @param list<int>       $lines     Line item IDs.
	 * @param string          $currency  Currency code.
	 */
	private function order(
		int $id,
		int $revenue,
		array $discounts,
		array $lines = array( 1 ),
		string $currency = 'EUR'
	): OrderSnapshot {
		return new OrderSnapshot(
			$id,
			$this->day(),
			new Money( $revenue, $currency ),
			$lines,
			array_map(
				static fn ( int $amount ): Money => new Money( $amount, $currency ),
				$discounts
			)
		);
	}

	/**
	 * Build an aggregator over given orders and costs.
	 *
	 * @param list<OrderSnapshot>  $orders The orders in the store.
	 * @param array<string, Money> $costs  Line costs keyed "order:line".
	 * @param bool                 $has_source Whether a cost system is installed at all.
	 */
	private function aggregator(
		array $orders,
		array $costs = array(),
		bool $has_source = true
	): AggregationService {
		return new AggregationService(
			new InMemoryOrderStatsRepository( $orders ),
			$this->stats,
			new CostSourceRegistry(
				$has_source ? array( new FakeCostSource( 'fake', true, 10, $costs ) ) : array()
			)
		);
	}

	/**
	 * Where the aggregator writes.
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
	 * One order using one coupon produces one row.
	 */
	public function test_one_order_with_one_coupon_makes_one_row(): void {
		$this->aggregator(
			array( $this->order( 100, 5000, array( 7 => 500 ) ) ),
			array( '100:1' => new Money( 2000, 'EUR' ) )
		)->aggregate_day( $this->day() );

		$rows = $this->stats->for_day( $this->day() );

		$this->assertCount( 1, $rows );
		$this->assertSame( 7, $rows[0]->coupon_id->value );
		$this->assertSame( 1, $rows[0]->orders );
		$this->assertSame( 5000, $rows[0]->net_revenue->amount );
		$this->assertSame( 500, $rows[0]->discount->amount );
		$this->assertSame( 2000, $rows[0]->cost->amount );
	}

	/**
	 * Margin follows from the row, and is revenue less cost.
	 */
	public function test_the_row_yields_a_margin(): void {
		$this->aggregator(
			array( $this->order( 100, 5000, array( 7 => 500 ) ) ),
			array( '100:1' => new Money( 2000, 'EUR' ) )
		)->aggregate_day( $this->day() );

		$this->assertSame( 3000, $this->stats->for_day( $this->day() )[0]->margin()?->amount );
	}

	/**
	 * Several orders on a day accumulate into one row per coupon.
	 */
	public function test_orders_accumulate_into_one_row_per_coupon(): void {
		$this->aggregator(
			array(
				$this->order( 100, 5000, array( 7 => 500 ) ),
				$this->order( 101, 3000, array( 7 => 300 ), array( 2 ) ),
			),
			array(
				'100:1' => new Money( 2000, 'EUR' ),
				'101:2' => new Money( 1000, 'EUR' ),
			)
		)->aggregate_day( $this->day() );

		$rows = $this->stats->for_day( $this->day() );

		$this->assertCount( 1, $rows );
		$this->assertSame( 2, $rows[0]->orders );
		$this->assertSame( 8000, $rows[0]->net_revenue->amount );
		$this->assertSame( 800, $rows[0]->discount->amount );
		$this->assertSame( 3000, $rows[0]->cost->amount );
	}

	/**
	 * An order using two coupons counts its full revenue against each.
	 *
	 * A coupon's row answers "what did orders using this coupon earn", which is
	 * the question a shop owner is actually asking and what WooCommerce's own
	 * coupon report answers. It does mean summing rows across coupons
	 * double-counts such an order — the figure is per coupon, not a partition of
	 * revenue, and a screen must not present it as one. Splitting revenue
	 * proportionally was the alternative, and it would attribute a number to
	 * each coupon that no order ever earned.
	 */
	public function test_an_order_with_two_coupons_counts_fully_against_each(): void {
		$this->aggregator(
			array(
				$this->order(
					100,
					5000,
					array(
						7 => 500,
						8 => 200,
					)
				),
			),
			array( '100:1' => new Money( 2000, 'EUR' ) )
		)->aggregate_day( $this->day() );

		$rows = $this->stats->for_day( $this->day() );
		usort( $rows, static fn ( $a, $b ): int => $a->coupon_id->value <=> $b->coupon_id->value );

		$this->assertCount( 2, $rows );
		$this->assertSame( 5000, $rows[0]->net_revenue->amount );
		$this->assertSame( 5000, $rows[1]->net_revenue->amount );
		$this->assertSame( 500, $rows[0]->discount->amount, 'Each coupon keeps its own discount.' );
		$this->assertSame( 200, $rows[1]->discount->amount );
	}

	/**
	 * Lines whose cost is unknown are counted, not guessed at. That count is
	 * what §6.3's coverage figure is built from.
	 */
	public function test_lines_without_a_cost_are_counted_as_uncovered(): void {
		$this->aggregator(
			array( $this->order( 100, 5000, array( 7 => 500 ), array( 1, 2, 3, 4 ) ) ),
			array(
				'100:1' => new Money( 1000, 'EUR' ),
				'100:2' => new Money( 1000, 'EUR' ),
				'100:3' => new Money( 1000, 'EUR' ),
			)
		)->aggregate_day( $this->day() );

		$row = $this->stats->for_day( $this->day() )[0];

		$this->assertSame( 3, $row->covered_lines );
		$this->assertSame( 4, $row->total_lines );
		$this->assertSame( CostCoverage::PARTIAL, $row->coverage() );
		$this->assertSame( 75, $row->coverage_percentage() );
		$this->assertSame( 3000, $row->cost->amount, 'Only the known costs are summed.' );
	}

	/**
	 * With no cost system installed there is no cost and no margin, rather than
	 * a margin equal to revenue.
	 */
	public function test_without_a_cost_source_there_is_no_margin(): void {
		$this->aggregator(
			array( $this->order( 100, 5000, array( 7 => 500 ) ) ),
			array(),
			false
		)->aggregate_day( $this->day() );

		$row = $this->stats->for_day( $this->day() )[0];

		$this->assertSame( CostCoverage::NONE, $row->coverage() );
		$this->assertNull( $row->margin() );
		$this->assertSame( 5000, $row->net_revenue->amount, 'Revenue is still worth recording.' );
	}

	/**
	 * Two currencies on one day give two rows for the same coupon, never one
	 * row with the amounts added together.
	 */
	public function test_currencies_are_aggregated_separately(): void {
		$this->aggregator(
			array(
				$this->order( 100, 5000, array( 7 => 500 ) ),
				$this->order( 101, 3000, array( 7 => 300 ), array( 2 ), 'USD' ),
			)
		)->aggregate_day( $this->day() );

		$rows = $this->stats->for_day( $this->day() );
		usort( $rows, static fn ( $a, $b ): int => strcmp( $a->currency(), $b->currency() ) );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'EUR', $rows[0]->currency() );
		$this->assertSame( 5000, $rows[0]->net_revenue->amount );
		$this->assertSame( 'USD', $rows[1]->currency() );
		$this->assertSame( 3000, $rows[1]->net_revenue->amount );
	}

	/**
	 * Aggregating the same day twice leaves the same figures, not doubled ones.
	 * The job has to be safe to re-run: refunds, edits and an overlapping
	 * backfill all cause exactly that.
	 */
	public function test_aggregating_a_day_twice_is_idempotent(): void {
		$aggregator = $this->aggregator(
			array( $this->order( 100, 5000, array( 7 => 500 ) ) ),
			array( '100:1' => new Money( 2000, 'EUR' ) )
		);

		$aggregator->aggregate_day( $this->day() );
		$aggregator->aggregate_day( $this->day() );

		$rows = $this->stats->for_day( $this->day() );

		$this->assertCount( 1, $rows );
		$this->assertSame( 1, $rows[0]->orders );
		$this->assertSame( 5000, $rows[0]->net_revenue->amount );
	}

	/**
	 * A day whose last coupon order was deleted must end up with no rows, not
	 * with yesterday's rows left behind.
	 */
	public function test_a_day_with_no_orders_is_cleared(): void {
		$this->aggregator( array() )->aggregate_day( $this->day() );

		$this->assertSame( array(), $this->stats->for_day( $this->day() ) );
		$this->assertSame( 1, $this->stats->writes, 'The day is still written, so stale rows go.' );
	}

	/**
	 * Which cost system produced the figures is recorded with them, so a store
	 * that switches plugins can be told why its numbers moved.
	 */
	public function test_the_cost_source_is_recorded_with_the_day(): void {
		$this->aggregator( array( $this->order( 100, 5000, array( 7 => 500 ) ) ) )
			->aggregate_day( $this->day() );

		$this->assertSame( 'fake', $this->stats->last_cost_source );
	}

	/**
	 * With no cost system, the row says so rather than naming one.
	 */
	public function test_no_cost_source_is_recorded_as_none(): void {
		$this->aggregator( array( $this->order( 100, 5000, array( 7 => 500 ) ) ), array(), false )
			->aggregate_day( $this->day() );

		$this->assertSame( '', $this->stats->last_cost_source );
	}
}
