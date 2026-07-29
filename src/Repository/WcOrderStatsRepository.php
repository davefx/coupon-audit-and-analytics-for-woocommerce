<?php
/**
 * Orders read from the WooCommerce analytics tables.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Repository;

use DateTimeImmutable;
use DateTimeZone;
use DFX\CouponAAW\Domain\Profit\Money;
use DFX\CouponAAW\Domain\Profit\OrderSnapshot;
use WC_Abstract_Order;

/**
 * Reads the orders behind the aggregates from WooCommerce Analytics (§6.1).
 *
 * Both lookup tables record `date_created` in the store's local time, so the
 * day boundaries below are local too and match what a shop owner means by
 * "yesterday".
 *
 * Refund rows are excluded: they carry a negative net total and a parent order,
 * and no coupon of their own. Aggregating them here would subtract revenue from
 * a coupon-day the refund does not belong to. Refunds are handled by
 * re-aggregating the original order's day, which is why aggregation replaces a
 * day whole rather than adding to it.
 */
final class WcOrderStatsRepository implements OrderStatsRepositoryInterface {

	/**
	 * Constructor.
	 *
	 * @param \wpdb        $wpdb     WordPress database handle.
	 * @param DateTimeZone $timezone The store's timezone.
	 * @param int          $decimals Places in the currency's minor unit.
	 */
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly DateTimeZone $timezone,
		private readonly int $decimals
	) {}

	/**
	 * Every countable order on a day that used at least one coupon.
	 *
	 * @param DateTimeImmutable $day The day, in the store's timezone.
	 *
	 * @return list<OrderSnapshot>
	 */
	public function orders_on( DateTimeImmutable $day ): array {
		$wpdb = $this->wpdb;

		$from = $day->setTime( 0, 0 )->format( 'Y-m-d H:i:s' );
		$to   = $day->modify( '+1 day' )->setTime( 0, 0 )->format( 'Y-m-d H:i:s' );

		$statuses     = $this->countable_statuses();
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		/*
		 * Table names go through %i and every value through a placeholder. Only
		 * the IN list is interpolated, and what it interpolates is a run of %s
		 * built from a count — placeholders, not values. The sniff cannot follow
		 * that, nor count arguments passed through array_merge.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.order_id, c.coupon_id, c.discount_amount, s.date_created, s.net_total
				FROM %i c
				INNER JOIN %i s ON s.order_id = c.order_id
				WHERE s.date_created >= %s AND s.date_created < %s
				AND s.parent_id = 0
				AND s.status IN ({$placeholders})",
				array_merge(
					array( $this->coupon_lookup_table(), $this->order_stats_table(), $from, $to ),
					$statuses
				)
			),
			ARRAY_A
		);
		// phpcs:enable

		return $this->to_snapshots( (array) $rows );
	}

	/**
	 * The day of the earliest coupon order, bounding any backfill.
	 */
	public function earliest_coupon_order_day(): ?DateTimeImmutable {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$earliest = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MIN(s.date_created) FROM %i c INNER JOIN %i s ON s.order_id = c.order_id',
				$this->coupon_lookup_table(),
				$this->order_stats_table()
			)
		);

		if ( ! is_string( $earliest ) || '' === $earliest || str_starts_with( $earliest, '0000' ) ) {
			return null;
		}

		return ( new DateTimeImmutable( $earliest, $this->timezone ) )->setTime( 0, 0 );
	}

	/**
	 * Group the joined rows into one snapshot per order.
	 *
	 * @param array<int, mixed> $rows Rows of order-and-coupon, as the database returned them.
	 *
	 * @return list<OrderSnapshot>
	 */
	private function to_snapshots( array $rows ): array {
		$orders = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$order_id = (int) ( $row['order_id'] ?? 0 );

			if ( $order_id < 1 ) {
				continue;
			}

			if ( ! isset( $orders[ $order_id ] ) ) {
				$currency = $this->currency_of( $order_id );

				if ( null === $currency ) {
					continue;
				}

				$orders[ $order_id ] = array(
					'date'      => new DateTimeImmutable( (string) ( $row['date_created'] ?? 'now' ), $this->timezone ),
					'revenue'   => Money::from_decimal( (float) ( $row['net_total'] ?? 0 ), $currency, $this->decimals ),
					'currency'  => $currency,
					'discounts' => array(),
				);
			}

			$orders[ $order_id ]['discounts'][ (int) ( $row['coupon_id'] ?? 0 ) ] = Money::from_decimal(
				(float) ( $row['discount_amount'] ?? 0 ),
				$orders[ $order_id ]['currency'],
				$this->decimals
			);
		}

		$line_items = $this->line_items_of( array_keys( $orders ) );
		$snapshots  = array();

		foreach ( $orders as $order_id => $order ) {
			$snapshots[] = new OrderSnapshot(
				$order_id,
				$order['date'],
				$order['revenue'],
				$line_items[ $order_id ] ?? array(),
				$order['discounts']
			);
		}

		return $snapshots;
	}

	/**
	 * The line item IDs of each of the given orders.
	 *
	 * One query for the lot rather than one per order: a busy day is exactly
	 * when this runs, and it runs in the background where nobody is watching it
	 * be slow.
	 *
	 * @param list<int> $order_ids The orders.
	 *
	 * @return array<int, list<int>>
	 */
	private function line_items_of( array $order_ids ): array {
		if ( array() === $order_ids ) {
			return array();
		}

		$wpdb         = $this->wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( $order_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_id, order_item_id FROM %i
				WHERE order_id IN ({$placeholders}) AND order_item_type = 'line_item'",
				array_merge( array( $wpdb->prefix . 'woocommerce_order_items' ), $order_ids )
			),
			ARRAY_A
		);
		// phpcs:enable

		$items = array();

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$items[ (int) ( $row['order_id'] ?? 0 ) ][] = (int) ( $row['order_item_id'] ?? 0 );
		}

		return $items;
	}

	/**
	 * An order's currency.
	 *
	 * Neither analytics table records it, and it cannot be assumed to be the
	 * store's current currency: a multi-currency shop's old orders were placed
	 * in whatever it was then. Loading the order is the only reliable answer
	 * that works on both post storage and HPOS.
	 *
	 * @param int $order_id The order.
	 */
	private function currency_of( int $order_id ): ?string {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Abstract_Order ) {
			return null;
		}

		$currency = $order->get_currency();

		return '' === $currency ? null : $currency;
	}

	/**
	 * The order statuses whose revenue counts, prefixed as the stats table
	 * stores them.
	 *
	 * The prefix is added with a prefix test rather than `ltrim`, which takes a
	 * set of characters and not a prefix: `ltrim( 'completed', 'wc-' )` returns
	 * "ompleted", because the word begins with a `c`. That silently produced a
	 * status no order has ever had, and the query matched nothing at all.
	 *
	 * @return list<string>
	 */
	private function countable_statuses(): array {
		return array_values(
			array_map(
				static fn ( string $status ): string
					=> str_starts_with( $status, 'wc-' ) ? $status : 'wc-' . $status,
				wc_get_is_paid_statuses()
			)
		);
	}

	/**
	 * The analytics coupon lookup table.
	 */
	private function coupon_lookup_table(): string {
		return $this->wpdb->prefix . 'wc_order_coupon_lookup';
	}

	/**
	 * The analytics order stats table.
	 */
	private function order_stats_table(): string {
		return $this->wpdb->prefix . 'wc_order_stats';
	}
}
