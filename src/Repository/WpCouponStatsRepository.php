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
	 * Constructor.
	 *
	 * @param \wpdb          $wpdb     WordPress database handle.
	 * @param SchemaMigrator $schema   Knows the table name.
	 * @param DateTimeZone   $timezone The store's timezone.
	 */
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly SchemaMigrator $schema,
		private readonly DateTimeZone $timezone
	) {}

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

		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$table,
				array(
					'coupon_id'     => $row->coupon_id->value,
					'stat_date'     => $date,
					'currency'      => $row->currency(),
					'orders'        => $row->orders,
					'net_revenue'   => $row->net_revenue->amount,
					'discount'      => $row->discount->amount,
					'cost'          => $row->cost->amount,
					'covered_lines' => $row->covered_lines,
					'total_lines'   => $row->total_lines,
					'cost_source'   => $cost_source,
					'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
				),
				array( '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s' )
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
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE stat_date = %s ORDER BY coupon_id, currency',
				$this->schema->table_name(),
				$day->format( 'Y-m-d' )
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
}
