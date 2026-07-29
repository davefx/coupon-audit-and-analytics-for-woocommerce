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
use DFX\CouponAAW\Domain\Clock\ClockInterface;
use DFX\CouponAAW\Repository\OrderStatsRepositoryInterface;
use DFX\CouponAAW\Service\AggregationService;
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
	 * The queue group, so a store can see whose jobs these are.
	 */
	private const GROUP = 'dfxcaaw';

	/**
	 * Constructor.
	 *
	 * @param AggregationService            $aggregation Recomputes a day.
	 * @param OrderStatsRepositoryInterface $orders      Bounds the backfill.
	 * @param SettingsInterface             $settings    Holds the backfill cursor.
	 * @param ClockInterface                $clock       Supplies today.
	 * @param DateTimeZone                  $timezone    The store's timezone.
	 */
	public function __construct(
		private readonly AggregationService $aggregation,
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

		as_schedule_single_action( time() + 60, self::AGGREGATE_DAY, $args, self::GROUP );
	}

	/**
	 * Aggregate one day. The queue's callback.
	 *
	 * @param string $day The day, as `Y-m-d`.
	 */
	public function run_day( string $day ): void {
		$this->aggregation->aggregate_day( new DateTimeImmutable( $day, $this->timezone ) );
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

		$this->aggregation->aggregate_day( $day );

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

		as_schedule_single_action( time() + 10, self::BACKFILL_STEP, array(), self::GROUP );
	}
}
