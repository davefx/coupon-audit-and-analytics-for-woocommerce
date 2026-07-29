<?php
/**
 * Aggregates storage contract.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Repository;

use DateTimeImmutable;
use DFX\CouponAAW\Domain\Profit\CouponDayStats;

/**
 * Reads and writes the aggregates table (§6.2).
 */
interface CouponStatsRepositoryInterface {

	/**
	 * Replace everything stored for one day.
	 *
	 * Aggregation is idempotent by construction: a day is recomputed whole and
	 * its previous rows discarded. Anything else would double-count when an
	 * order is refunded, edited or re-aggregated by a backfill that overlaps
	 * work already done.
	 *
	 * @param DateTimeImmutable    $day         The day being replaced.
	 * @param list<CouponDayStats> $rows        The figures for that day.
	 * @param string               $cost_source Which cost system produced the costs.
	 */
	public function replace_day( DateTimeImmutable $day, array $rows, string $cost_source ): void;

	/**
	 * Everything stored for one day.
	 *
	 * @param DateTimeImmutable $day The day to read.
	 *
	 * @return list<CouponDayStats>
	 */
	public function for_day( DateTimeImmutable $day ): array;

	/**
	 * Everything stored between two days, inclusive.
	 *
	 * @param DateTimeImmutable $from First day.
	 * @param DateTimeImmutable $to   Last day.
	 *
	 * @return list<CouponDayStats>
	 */
	public function between( DateTimeImmutable $from, DateTimeImmutable $to ): array;
}
