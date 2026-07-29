<?php
/**
 * Recomputing one day.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Service;

use DateTimeImmutable;

/**
 * Recomputes a single day's figures.
 *
 * A seam rather than a convenience. `Aggregator` is a state machine — where the
 * backfill has got to, whether it has finished, what happens when it is started
 * twice — and none of that has anything to do with what aggregating a day
 * actually computes. Without this the two can only be tested together, against a
 * database, which is a slow way to ask whether a cursor advanced.
 */
interface AggregationInterface {

	/**
	 * Recompute one day from the orders, replacing whatever was stored for it.
	 *
	 * @param DateTimeImmutable $day The day to recompute.
	 *
	 * @return int How many coupon rows were written.
	 */
	public function aggregate_day( DateTimeImmutable $day ): int;
}
