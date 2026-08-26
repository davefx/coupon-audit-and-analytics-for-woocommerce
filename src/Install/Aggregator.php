<?php
/**
 * Background aggregation.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Install;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use DFX\CouponAAW\Domain\Clock\ClockInterface;
use DFX\CouponAAW\Repository\OrderStatsRepositoryInterface;
use DFX\CouponAAW\Service\AggregationInterface;
use DFX\CouponAAW\Support\SettingsInterface;
use WC_Abstract_Order;

/**
 * Runs aggregation through Action Scheduler (§6.2).
 *
 * Two ways in. Incrementally, when an order reaches a state whose revenue
 * counts, the day that order belongs to is queued for recomputation. And
 * retroactively, a backfill walks the store's history one day at a time,
 * scheduling the next day only once the current one is done — so the work is
 * resumable by construction, survives a timeout, and reports progress simply by
 * saying where it has got to.
 *
 * Days are recomputed rather than adjusted, so a day queued twice costs time and
 * nothing else.
 */
final class Aggregator {

	/**
	 * Action fired to aggregate one day.
	 */
	public const AGGREGATE_DAY = 'dfxcaaw_aggregate_day';

	/**
	 * Action fired to continue the backfill.
	 */
	public const BACKFILL_STEP = 'dfxcaaw_backfill_step';

	/**
	 * Where the backfill records the day it has reached.
	 */
	private const BACKFILL_CURSOR = 'backfill_cursor';

	/**
	 * Where the backfill records that it has finished.
	 */
	private const BACKFILL_DONE = 'backfill_complete';

	/**
	 * Where the days that would not aggregate are remembered.
	 */
	private const FAILED_DAYS = 'failed_days';

	/**
	 * How many times a day is tried again before it is left alone.
	 *
	 * Retrying for ever is doing nothing, at the cost of a job every quarter of
	 * an hour. What must not happen is forgetting: a day that has given up is
	 * still missing, and `failed_days()` is how something can say so.
	 */
	public const RETRY_ATTEMPTS = 5;

	/**
	 * How long to wait before trying a failed day again, in seconds.
	 *
	 * Multiplied by the attempt, so the gaps widen. A day that fails because the
	 * database was briefly away wants a short wait; a day that fails because the
	 * shop holds something this plugin cannot read wants to stop wasting the
	 * shop's queue.
	 */
	private const RETRY_DELAY = 900;

	/**
	 * The queue group, so a store can see whose jobs these are.
	 */
	private const GROUP = 'dfxcaaw';

	/**
	 * Constructor.
	 *
	 * @param AggregationInterface          $aggregation Recomputes a day.
	 * @param OrderStatsRepositoryInterface $orders      Bounds the backfill.
	 * @param SettingsInterface             $settings    Holds the backfill cursor.
	 * @param ClockInterface                $clock       Supplies today.
	 * @param DateTimeZone                  $timezone    The store's timezone.
	 */
	public function __construct(
		private readonly AggregationInterface $aggregation,
		private readonly OrderStatsRepositoryInterface $orders,
		private readonly SettingsInterface $settings,
		private readonly ClockInterface $clock,
		private readonly DateTimeZone $timezone
	) {}

	/**
	 * Queue the day an order belongs to.
	 *
	 * Called when an order changes state. The order's own day is recomputed,
	 * which is also how a refund is accounted for: the refund is not aggregated
	 * itself, the day that produced it is done again.
	 *
	 * @param int $order_id The order that changed.
	 */
	public function queue_order( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Abstract_Order ) {
			return;
		}

		$created = $order->get_date_created();

		if ( null === $created ) {
			return;
		}

		$this->queue_day( ( new DateTimeImmutable( $created->date( 'Y-m-d' ), $this->timezone ) ) );
	}

	/**
	 * Queue one day, unless it is already queued.
	 *
	 * @param DateTimeImmutable $day The day to recompute.
	 */
	public function queue_day( DateTimeImmutable $day ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			// Without Action Scheduler there is no queue to join, so the work
			// happens now rather than not at all. WooCommerce bundles it, but a
			// store is not obliged to keep it loadable.
			$this->aggregation->aggregate_day( $day );

			return;
		}

		$args = array( 'day' => $day->format( 'Y-m-d' ) );

		if ( as_next_scheduled_action( self::AGGREGATE_DAY, $args, self::GROUP ) ) {
			return;
		}

		as_schedule_single_action( $this->clock->now()->getTimestamp() + 60, self::AGGREGATE_DAY, $args, self::GROUP );
	}

	/**
	 * Aggregate one day. The queue's callback.
	 *
	 * A day that throws is remembered and queued again rather than lost. Before
	 * this, a day whose aggregation failed looked exactly like a day on which no
	 * coupon was used — no figures, no retry, and nothing anywhere saying which
	 * of the two had happened.
	 *
	 * The exception is re-thrown after being recorded, so that Action Scheduler
	 * logs what went wrong. The retry is queued independently of that: the point
	 * of catching is to remember and try again, not to hide.
	 *
	 * @param string $day The day, as `Y-m-d`.
	 *
	 * @throws Throwable Whatever aggregation threw, after recording it.
	 */
	public function run_day( string $day ): void {
		try {
			$this->aggregation->aggregate_day( new DateTimeImmutable( $day, $this->timezone ) );
		} catch ( Throwable $failure ) {
			$this->remember_failure( $day );

			throw $failure;
		}

		$this->forget_failure( $day );
	}

	/**
	 * The days that would not aggregate, oldest first.
	 *
	 * @return list<string>
	 */
	public function failed_days(): array {
		$failures = $this->failures();

		ksort( $failures );

		return array_keys( $failures );
	}

	/**
	 * Give every recorded day another go, from a clean slate.
	 *
	 * Called when a new version arrives. The attempt counts start over, because
	 * what is being retried is not the same attempt — the code that failed has
	 * been replaced, and a day that had used up its retries against the old one
	 * deserves a full set against the new.
	 *
	 * The jobs are spread a minute apart. A shop that was broken for a year has
	 * a year of days recorded, and queueing them all for the same instant makes
	 * the update itself the outage.
	 */
	public function retry_failed_days(): void {
		$days = $this->failed_days();

		if ( array() === $days ) {
			return;
		}

		$this->settings->set( self::FAILED_DAYS, array_fill_keys( $days, 0 ) );

		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			// Deliberately not aggregating inline here. queue_day() does that
			// when there is no queue, which is right for one day at the moment
			// an order changes and quite wrong for a year of them during a page
			// load.
			return;
		}

		$at = $this->clock->now()->getTimestamp();

		foreach ( $days as $offset => $day ) {
			$args = array( 'day' => $day );

			if ( as_next_scheduled_action( self::AGGREGATE_DAY, $args, self::GROUP ) ) {
				continue;
			}

			as_schedule_single_action( $at + ( 60 * ( $offset + 1 ) ), self::AGGREGATE_DAY, $args, self::GROUP );
		}
	}

	/**
	 * Record that a day would not aggregate, and queue it to be tried again.
	 *
	 * @param string $day The day, as `Y-m-d`.
	 */
	private function remember_failure( string $day ): void {
		$failures = $this->failures();
		$attempts = ( $failures[ $day ] ?? 0 ) + 1;

		$failures[ $day ] = $attempts;
		$this->settings->set( self::FAILED_DAYS, $failures );

		if ( $attempts > self::RETRY_ATTEMPTS ) {
			return;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			// Without a queue there is nothing to retry with. The day stays
			// recorded, which is the part that matters: it is still missing.
			return;
		}

		$args = array( 'day' => $day );

		if ( as_next_scheduled_action( self::AGGREGATE_DAY, $args, self::GROUP ) ) {
			return;
		}

		as_schedule_single_action(
			$this->clock->now()->getTimestamp() + ( self::RETRY_DELAY * $attempts ),
			self::AGGREGATE_DAY,
			$args,
			self::GROUP
		);
	}

	/**
	 * Forget a day that aggregated after all.
	 *
	 * @param string $day The day, as `Y-m-d`.
	 */
	private function forget_failure( string $day ): void {
		$failures = $this->failures();

		if ( ! isset( $failures[ $day ] ) ) {
			return;
		}

		unset( $failures[ $day ] );
		$this->settings->set( self::FAILED_DAYS, $failures );
	}

	/**
	 * The recorded failures, as day to attempts.
	 *
	 * @return array<string, int>
	 */
	private function failures(): array {
		$stored = $this->settings->get( self::FAILED_DAYS, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$failures = array();

		foreach ( $stored as $day => $attempts ) {
			$failures[ (string) $day ] = (int) $attempts;
		}

		return $failures;
	}

	/**
	 * Begin walking the store's history, oldest day first.
	 *
	 * Does nothing if the backfill has already run: activation happens every
	 * time a plugin is switched on, and a store with three years of orders
	 * should not redo all of it because someone toggled the plugin.
	 */
	public function start_backfill(): void {
		if ( true === $this->settings->get( self::BACKFILL_DONE, false ) ) {
			return;
		}

		$earliest = $this->orders->earliest_coupon_order_day();

		if ( null === $earliest ) {
			$this->settings->set( self::BACKFILL_DONE, true );

			return;
		}

		$cursor = $this->settings->get_string( self::BACKFILL_CURSOR ) ?? $earliest->format( 'Y-m-d' );

		$this->settings->set( self::BACKFILL_CURSOR, $cursor );
		$this->schedule_step();
	}

	/**
	 * Aggregate the day the backfill has reached, then queue the next.
	 *
	 * One day per run, chaining rather than looping: a loop over three years of
	 * history is one job that times out halfway and starts again from nothing,
	 * whereas a chain resumes from wherever it stopped.
	 */
	public function run_backfill_step(): void {
		$cursor = $this->settings->get_string( self::BACKFILL_CURSOR );

		if ( null === $cursor ) {
			return;
		}

		$day   = new DateTimeImmutable( $cursor, $this->timezone );
		$today = $this->clock->now()->setTime( 0, 0 );

		if ( $day > $today ) {
			$this->settings->set( self::BACKFILL_DONE, true );

			return;
		}

		/*
		 * The cursor advances and the successor is queued whether or not the day
		 * aggregated. This is the half that mattered: both used to happen after
		 * the aggregation, so a day that threw stopped the walk where it stood,
		 * and a shop with years of history behind that day never saw any of it.
		 *
		 * The failure is not swallowed — it is recorded and retried on its own
		 * schedule, which is what lets the walk carry on without losing the day.
		 */
		try {
			$this->aggregation->aggregate_day( $day );
			$this->forget_failure( $cursor );
		} catch ( Throwable $failure ) {
			unset( $failure );

			$this->remember_failure( $cursor );
		}

		$this->settings->set( self::BACKFILL_CURSOR, $day->modify( '+1 day' )->format( 'Y-m-d' ) );
		$this->schedule_step();
	}

	/**
	 * How far the backfill has got, as a day, or null when it is finished or
	 * has not started.
	 */
	public function backfill_cursor(): ?string {
		if ( true === $this->settings->get( self::BACKFILL_DONE, false ) ) {
			return null;
		}

		return $this->settings->get_string( self::BACKFILL_CURSOR );
	}

	/**
	 * Queue the next step of the backfill.
	 */
	private function schedule_step(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		if ( as_next_scheduled_action( self::BACKFILL_STEP, array(), self::GROUP ) ) {
			return;
		}

		as_schedule_single_action( $this->clock->now()->getTimestamp() + 10, self::BACKFILL_STEP, array(), self::GROUP );
	}
}
