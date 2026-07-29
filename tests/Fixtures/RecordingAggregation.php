<?php
/**
 * An aggregation that only remembers what it was asked.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Fixtures;

use DateTimeImmutable;
use DFX\CouponAAW\Service\AggregationInterface;

/**
 * Records the days it was asked to aggregate, and computes nothing.
 *
 * What the backfill is for is walking every day exactly once, in order, without
 * losing its place. That is answerable from the list of days it asked for.
 */
final class RecordingAggregation implements AggregationInterface {

	/**
	 * The days aggregated, in the order they were asked for.
	 *
	 * @var list<string>
	 */
	public array $days = array();

	/**
	 * Record a day.
	 *
	 * @param DateTimeImmutable $day The day to recompute.
	 */
	public function aggregate_day( DateTimeImmutable $day ): int {
		$this->days[] = $day->format( 'Y-m-d' );

		return 0;
	}
}
