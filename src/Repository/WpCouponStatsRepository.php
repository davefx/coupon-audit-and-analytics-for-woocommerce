<?php
/**
 * Aggregates stored in the plugin's own table.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Repository;

use DateTimeImmutable;
use DateTimeZone;
use DFX\CouponAAW\Domain\Coupon\CouponId;
use DFX\CouponAAW\Domain\Profit\CouponDayStats;
use DFX\CouponAAW\Domain\Profit\CouponTotals;
use DFX\CouponAAW\Domain\Profit\Money;
use DFX\CouponAAW\Install\SchemaMigrator;

/**
 * Reads and writes the aggregates table (§6.2).
 *
 * Amounts are stored as integer minor units, so nothing here passes a monetary
 * value through a float on the way in or out.
 */
final class WpCouponStatsRepository implements CouponStatsRepositoryInterface {

	/**
     * @var \wpdb
     * @readonly
     */
    private \wpdb $wpdb;
    /**
     * @var SchemaMigrator
     * @readonly
     */
    private SchemaMigrator $schema;
    /**
     * @var DateTimeZone
     * @readonly
     */
    private DateTimeZone $timezone;
    /**
	 * How many rows one INSERT carries.
	 *
	 * Large enough that a normal day is one statement, small enough that an
	 * abnormal one cannot outgrow max_allowed_packet. Eleven small columns, so
	 * five hundred rows is tens of kilobytes.
	 */
	private const INSERT_CHUNK = 500;

	/**
	 * Constructor.
	 *
	 * @param \wpdb          $wpdb     WordPress database handle.
	 * @param SchemaMigrator $schema   Knows the table name.
	 * @param DateTimeZone   $timezone The store's timezone.
	 */
	public function __construct(\wpdb $wpdb, SchemaMigrator $schema, DateTimeZone $timezone)
    {
        $this->wpdb = $wpdb;
        $this->schema = $schema;
        $this->timezone = $timezone;
    }

	/**
	 * Replace everything stored for one day.
	 *
	 * Delete then insert, so a day whose last coupon order was removed ends up
	 * empty rather than keeping figures nothing produces any more.
	 *
	 * @param DateTimeImmutable    $day         The day being replaced.
	 * @param list<CouponDayStats> $rows        The figures for that day.
	 * @param string               $cost_source Which cost system produced the costs.
	 */
	public function replace_day( DateTimeImmutable $day, array $rows, string $cost_source ): void {
		$wpdb  = $this->wpdb;
		$table = $this->schema->table_name();
		$date  = $day->format( 'Y-m-d' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, array( 'stat_date' => $date ), array( '%s' ) );

		if ( array() === $rows ) {
			return;
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		/*
		 * Batched rather than a row at a time. A day holds one row per coupon
		 * used that day, and the backfill writes one day per step across the
		 * whole of a shop's history: at the size this plugin is for that was a
		 * couple of thousand round trips per step and millions across a
		 * backfill, to write a few hundred kilobytes.
		 *
		 * Chunked so that no single statement grows without limit. A day nobody
		 * expected — a shop that mints a coupon per customer and then runs a
		 * campaign — must not produce a statement past max_allowed_packet, which
		 * fails the whole day rather than slowing it.
		 */
		foreach ( array_chunk( $rows, self::INSERT_CHUNK ) as $chunk ) {
			$values = array();

			foreach ( $chunk as $row ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepare() returns the placeholders already filled.
				$values[] = $wpdb->prepare(
					'(%d, %s, %s, %d, %d, %d, %d, %d, %d, %s, %s)',
					$row->coupon_id->value,
					$date,
					$row->currency(),
					$row->orders,
					$row->net_revenue->amount,
					$row->discount->amount,
					$row->cost->amount,
					$row->covered_lines,
					$row->total_lines,
					$cost_source,
					$now
				);
			}

			// Every value went through prepare() above; what is interpolated
			// here is the result of that, which the sniff cannot follow.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i
						(coupon_id, stat_date, currency, orders, net_revenue, discount,
						 cost, covered_lines, total_lines, cost_source, updated_at)
					VALUES ',
					$table
				) . implode( ', ', $values )
			);
		}
	}

	/**
	 * Everything stored for one day.
	 *
	 * @param DateTimeImmutable $day The day to read.
	 *
	 * @return list<CouponDayStats>
	 */
	public function for_day( DateTimeImmutable $day ): array {
		return $this->between( $day, $day );
	}

	/**
	 * Everything stored between two days, inclusive.
	 *
	 * @param DateTimeImmutable $from First day.
	 * @param DateTimeImmutable $to   Last day.
	 *
	 * @return list<CouponDayStats>
	 */
	public function between( DateTimeImmutable $from, DateTimeImmutable $to ): array {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE stat_date BETWEEN %s AND %s ORDER BY stat_date, coupon_id, currency',
				$this->schema->table_name(),
				$from->format( 'Y-m-d' ),
				$to->format( 'Y-m-d' )
			),
			ARRAY_A
		);

		$stats = array();

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$currency = (string) ( $row['currency'] ?? '' );

			$stats[] = new CouponDayStats(
				new CouponId( (int) ( $row['coupon_id'] ?? 0 ) ),
				new DateTimeImmutable( (string) ( $row['stat_date'] ?? 'now' ), $this->timezone ),
				(int) ( $row['orders'] ?? 0 ),
				new Money( (int) ( $row['net_revenue'] ?? 0 ), $currency ),
				new Money( (int) ( $row['discount'] ?? 0 ), $currency ),
				new Money( (int) ( $row['cost'] ?? 0 ), $currency ),
				(int) ( $row['covered_lines'] ?? 0 ),
				(int) ( $row['total_lines'] ?? 0 )
			);
		}

		return $stats;
	}

	/**
	 * One page of per-coupon totals over a window, biggest earner first.
	 *
	 * One GROUP BY over the window rather than a row per coupon per day dragged
	 * into PHP and added up there. Every column being summed is an integer in
	 * the currency's minor unit, so SUM() is exact — this is the reason money is
	 * never a float here (§8.4), and it is what makes totalling in the database
	 * safe rather than merely fast.
	 *
	 * The ORDER BY cannot use an index: the value it sorts on does not exist
	 * until the window has been grouped. That is fine — the alternative is
	 * transferring every row and sorting them in PHP, which is the same work in
	 * a slower place and a much larger result.
	 *
	 * @param DateTimeImmutable $from   First day.
	 * @param DateTimeImmutable $to     Last day.
	 * @param int               $limit  How many rows to return.
	 * @param int               $offset How many to skip first.
	 *
	 * @return list<CouponTotals>
	 */
	public function totals_between( DateTimeImmutable $from, DateTimeImmutable $to, int $limit, int $offset ): array {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT coupon_id, currency,
					SUM( orders ) AS orders,
					SUM( net_revenue ) AS net_revenue,
					SUM( discount ) AS discount,
					SUM( cost ) AS cost,
					SUM( covered_lines ) AS covered_lines,
					SUM( total_lines ) AS total_lines
				FROM %i
				WHERE stat_date BETWEEN %s AND %s
				GROUP BY coupon_id, currency
				ORDER BY SUM( net_revenue ) DESC, coupon_id ASC, currency ASC
				LIMIT %d OFFSET %d',
				$this->schema->table_name(),
				$from->format( 'Y-m-d' ),
				$to->format( 'Y-m-d' ),
				max( 0, $limit ),
				max( 0, $offset )
			),
			ARRAY_A
		);

		$totals = array();

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$currency = (string) ( $row['currency'] ?? '' );

			$totals[] = new CouponTotals(
				new CouponId( (int) ( $row['coupon_id'] ?? 0 ) ),
				(int) ( $row['orders'] ?? 0 ),
				new Money( (int) ( $row['net_revenue'] ?? 0 ), $currency ),
				new Money( (int) ( $row['discount'] ?? 0 ), $currency ),
				new Money( (int) ( $row['cost'] ?? 0 ), $currency ),
				(int) ( $row['covered_lines'] ?? 0 ),
				(int) ( $row['total_lines'] ?? 0 )
			);
		}

		return $totals;
	}

	/**
	 * How many lines a window comes to, and how many of them have any cost.
	 *
	 * One pass over the grouped window for both, rather than one query for the
	 * pagination total and another for the coverage notice: they are the same
	 * GROUP BY, and asking twice would run it twice.
	 *
	 * "Has any cost" is `covered_lines > 0` summed per coupon and currency,
	 * which is what `CostCoverage::from_counts()` calls anything other than
	 * NONE. The two must agree — a screen that says nothing has cost while
	 * showing a margin is worse than either message alone.
	 *
	 * @param DateTimeImmutable $from First day.
	 * @param DateTimeImmutable $to   Last day.
	 *
	 * @return array{lines: int, with_cost: int}
	 */
	public function coverage_between( DateTimeImmutable $from, DateTimeImmutable $to ): array {
		$wpdb = $this->wpdb;

		// Counted over a derived table rather than with COUNT(DISTINCT a, b):
		// the two agree, but the distinct form is an extension not every engine
		// spells the same way, and this one plainly says what is being asked.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS lines_in_window,
					COALESCE( SUM( covered > 0 ), 0 ) AS with_cost
				FROM (
					SELECT SUM( covered_lines ) AS covered
					FROM %i
					WHERE stat_date BETWEEN %s AND %s
					GROUP BY coupon_id, currency
				) AS totals',
				$this->schema->table_name(),
				$from->format( 'Y-m-d' ),
				$to->format( 'Y-m-d' )
			),
			ARRAY_A
		);

		return array(
			'lines'     => (int) ( $row['lines_in_window'] ?? 0 ),
			'with_cost' => (int) ( $row['with_cost'] ?? 0 ),
		);
	}
}
