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
use DFX\CouponAAW\Domain\Profit\CouponTotals;

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

	/**
	 * One page of per-coupon totals over a window, biggest earner first.
	 *
	 * The summing happens here rather than in the service. `between()` returns
	 * a row per coupon per day per currency, and a thirty-day window at
	 * twenty-six thousand coupons is hundreds of thousands of them — every one
	 * built into an object so it can be added to a running total and thrown
	 * away. A year, which the window filter allows, is millions. Adding numbers
	 * up is what a database is for.
	 *
	 * Currencies are never summed together (§8.5), so a coupon taking payment
	 * in two of them is two rows here and two lines on the screen.
	 *
	 * @param DateTimeImmutable $from   First day.
	 * @param DateTimeImmutable $to     Last day.
	 * @param int               $limit  How many rows to return.
	 * @param int               $offset How many to skip first.
	 *
	 * @return list<CouponTotals>
	 */
	public function totals_between( DateTimeImmutable $from, DateTimeImmutable $to, int $limit, int $offset ): array;

	/**
	 * How many lines a window comes to, and how many of them have any cost.
	 *
	 * Both are facts about the window rather than about a page, which is why
	 * they are asked for together and separately from the rows. The screen
	 * paginates on the first and explains itself with the second: a notice
	 * saying "none of these orders has a cost recorded" must not become true on
	 * page three and false on page four, so it cannot be derived from the twenty
	 * rows in front of the reader.
	 *
	 * A coupon paid for in two currencies counts twice in both, because it is
	 * two lines on the screen (§8.5).
	 *
	 * @param DateTimeImmutable $from First day.
	 * @param DateTimeImmutable $to   Last day.
	 *
	 * @return array{lines: int, with_cost: int}
	 */
	public function coverage_between( DateTimeImmutable $from, DateTimeImmutable $to ): array;
}
