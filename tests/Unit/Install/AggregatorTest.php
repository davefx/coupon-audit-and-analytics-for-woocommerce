<?php
/**
 * Backfill tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Install;

use DateTimeImmutable;
use DateTimeZone;
use DFX\CouponAAW\Domain\Coupon\CouponId;
use DFX\CouponAAW\Domain\Profit\Money;
use DFX\CouponAAW\Domain\Profit\OrderSnapshot;
use DFX\CouponAAW\Install\Aggregator;
use DFX\CouponAAW\Tests\Fixtures\FrozenClock;
use DFX\CouponAAW\Tests\Fixtures\InMemoryOrderStatsRepository;
use DFX\CouponAAW\Tests\Fixtures\InMemorySettings;
use DFX\CouponAAW\Tests\Fixtures\RecordingAggregation;
use PHPUnit\Framework\TestCase;

/**
 * The backfill walks a store's history one day at a time.
 *
 * It is built to be interrupted: it records where it has got to before doing
 * anything, and each step schedules the next rather than looping. So what has to
 * be true is that it starts where it left off, never skips a day, never does one
 * twice in a run, and stops — an unbounded chain of scheduled jobs is worse than
 * no backfill at all.
 *
 * None of that involves Action Scheduler, which is absent here. Without it the
 * aggregator does the work synchronously, which is both the documented fallback
 * and what makes the state machine observable a step at a time.
 */
final class AggregatorTest extends TestCase {

	/**
	 * The days the aggregator asked for, in order.
	 *
	 * @var RecordingAggregation
	 */
	private RecordingAggregation $recorder;

	/**
	 * Where the backfill records its progress.
	 *
	 * @var InMemorySettings
	 */
	private InMemorySettings $settings;

	/**
	 * The store's timezone.
	 */
	private function timezone(): DateTimeZone {
		return new DateTimeZone( 'Europe/Madrid' );
	}

	/**
	 * An order placed on a given day, with a coupon on it.
	 *
	 * @param string $day The day it was placed.
	 */
	private function order( string $day ): OrderSnapshot {
		return new OrderSnapshot(
			1,
			new DateTimeImmutable( $day, $this->timezone() ),
			new Money( 10000, 'EUR' ),
			array( 1 ),
			array( 7 => new Money( 500, 'EUR' ) )
		);
	}

	/**
	 * An aggregator over the given history.
	 *
	 * @param list<OrderSnapshot>  $orders The store's orders.
	 * @param array<string, mixed> $stored Settings already stored.
	 * @param string               $today  What the clock reads.
	 */
	private function aggregator( array $orders, array $stored, string $today ): Aggregator {
		$this->recorder = new RecordingAggregation();
		$this->settings = new InMemorySettings( $stored );

		return new Aggregator(
			$this->recorder,
			new InMemoryOrderStatsRepository( $orders ),
			$this->settings,
			FrozenClock::at( $today ),
			$this->timezone()
		);
	}

	/**
	 * A store whose history has already been walked is not walked again.
	 *
	 * Activation runs every time the plugin is switched on, and three years of
	 * orders should not be recomputed because somebody toggled it.
	 */
	public function test_a_finished_backfill_is_not_started_again(): void {
		$aggregator = $this->aggregator(
			array( $this->order( '2026-01-01' ) ),
			array( 'backfill_complete' => true ),
			'2026-07-28'
		);

		$aggregator->start_backfill();

		$this->assertSame( array(), $this->recorder->days );
		$this->assertNull( $this->settings->get_string( 'backfill_cursor' ) );
	}

	/**
	 * A store with no coupon orders at all has nothing to walk, and says so
	 * rather than leaving a backfill that never finishes.
	 */
	public function test_a_store_with_no_coupon_orders_finishes_immediately(): void {
		$aggregator = $this->aggregator( array(), array(), '2026-07-28' );

		$aggregator->start_backfill();

		$this->assertTrue( $this->settings->get( 'backfill_complete' ) );
		$this->assertNull( $aggregator->backfill_cursor() );
	}

	/**
	 * The backfill starts at the oldest day that has a coupon order, not at the
	 * oldest order. Days before that have nothing this plugin reports on.
	 */
	public function test_it_starts_at_the_earliest_coupon_order(): void {
		$aggregator = $this->aggregator(
			array( $this->order( '2026-06-01' ), $this->order( '2026-06-10' ) ),
			array(),
			'2026-07-28'
		);

		$aggregator->start_backfill();

		$this->assertSame( '2026-06-01', $this->settings->get_string( 'backfill_cursor' ) );
	}

	/**
	 * Each step aggregates the day it has reached and moves on by one.
	 */
	public function test_a_step_aggregates_its_day_and_advances(): void {
		$aggregator = $this->aggregator(
			array( $this->order( '2026-06-01' ) ),
			array( 'backfill_cursor' => '2026-06-01' ),
			'2026-07-28'
		);

		$aggregator->run_backfill_step();

		$this->assertSame( array( '2026-06-01' ), $this->recorder->days );
		$this->assertSame( '2026-06-02', $this->settings->get_string( 'backfill_cursor' ) );
	}

	/**
	 * Restarted from where it stopped, the walk covers every day once and in
	 * order. This is the property the whole design exists for.
	 */
	public function test_it_walks_every_day_once_and_in_order(): void {
		$aggregator = $this->aggregator(
			array( $this->order( '2026-07-25' ) ),
			array(),
			'2026-07-28'
		);

		$aggregator->start_backfill();

		// Each step stands for one queued job, run one at a time as the queue
		// would run them.
		for ( $i = 0; $i < 10; $i++ ) {
			$aggregator->run_backfill_step();
		}

		$this->assertSame(
			array( '2026-07-25', '2026-07-26', '2026-07-27', '2026-07-28' ),
			$this->recorder->days
		);
	}

	/**
	 * The walk stops once it passes today, and stays stopped. A chain that keeps
	 * scheduling itself is worse than no backfill: it never stops costing a store
	 * anything.
	 */
	public function test_it_stops_once_it_passes_today(): void {
		$aggregator = $this->aggregator(
			array( $this->order( '2026-07-27' ) ),
			array( 'backfill_cursor' => '2026-07-29' ),
			'2026-07-28'
		);

		$aggregator->run_backfill_step();

		$this->assertSame( array(), $this->recorder->days );
		$this->assertTrue( $this->settings->get( 'backfill_complete' ) );

		$aggregator->run_backfill_step();

		$this->assertSame( array(), $this->recorder->days, 'A finished backfill kept working.' );
	}

	/**
	 * Today itself is aggregated rather than skipped. A shop that installs the
	 * plugin at noon has already taken orders that morning.
	 */
	public function test_today_is_included(): void {
		$aggregator = $this->aggregator(
			array( $this->order( '2026-07-28' ) ),
			array( 'backfill_cursor' => '2026-07-28' ),
			'2026-07-28'
		);

		$aggregator->run_backfill_step();

		$this->assertSame( array( '2026-07-28' ), $this->recorder->days );
	}

	/**
	 * A step with nowhere to resume from does nothing, rather than starting the
	 * whole history over from an assumed date.
	 */
	public function test_a_step_without_a_cursor_does_nothing(): void {
		$aggregator = $this->aggregator( array( $this->order( '2026-06-01' ) ), array(), '2026-07-28' );

		$aggregator->run_backfill_step();

		$this->assertSame( array(), $this->recorder->days );
	}

	/**
	 * Progress is reportable while the walk is running, and is nothing once it
	 * has finished — which is what the settings screen shows.
	 */
	public function test_progress_is_the_day_it_has_reached(): void {
		$aggregator = $this->aggregator(
			array( $this->order( '2026-07-27' ) ),
			array(),
			'2026-07-28'
		);

		$aggregator->start_backfill();

		$this->assertSame( '2026-07-27', $aggregator->backfill_cursor() );

		$aggregator->run_backfill_step();
		$aggregator->run_backfill_step();
		$aggregator->run_backfill_step();

		$this->assertNull( $aggregator->backfill_cursor(), 'A finished backfill still reports progress.' );
	}

	/**
	 * A backfill interrupted mid-history resumes from its cursor rather than
	 * from the beginning. Without this, a store with three years of orders that
	 * times out once would redo the lot on the next run.
	 */
	public function test_an_interrupted_backfill_resumes_where_it_stopped(): void {
		$aggregator = $this->aggregator(
			array( $this->order( '2020-01-01' ) ),
			array( 'backfill_cursor' => '2026-07-26' ),
			'2026-07-28'
		);

		$aggregator->start_backfill();

		$this->assertSame(
			'2026-07-26',
			$this->settings->get_string( 'backfill_cursor' ),
			'Starting again reset the cursor to the beginning of the store history.'
		);
	}

	/**
	 * Aggregating one named day is what the queue's callback does, and it reads
	 * the day in the store's timezone rather than the server's.
	 */
	public function test_it_aggregates_a_named_day(): void {
		$aggregator = $this->aggregator( array(), array(), '2026-07-28' );

		$aggregator->run_day( '2026-03-15' );

		$this->assertSame( array( '2026-03-15' ), $this->recorder->days );
	}

	/**
	 * Without Action Scheduler the work happens now rather than not at all.
	 * WooCommerce bundles it, but a store is not obliged to keep it loadable, and
	 * silently aggregating nothing would be indistinguishable from a shop with no
	 * coupon sales.
	 */
	public function test_a_queued_day_is_done_now_when_there_is_no_queue(): void {
		$aggregator = $this->aggregator( array(), array(), '2026-07-28' );

		$aggregator->queue_day( new DateTimeImmutable( '2026-05-04', $this->timezone() ) );

		$this->assertSame( array( '2026-05-04' ), $this->recorder->days );
	}
}
