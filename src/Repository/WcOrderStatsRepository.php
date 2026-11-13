<?php
/**
 * Orders read from the WooCommerce analytics tables.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Repository;

use DateTimeImmutable;
use Automattic\WooCommerce\Utilities\OrderUtil;
use DateTimeZone;
use DFX\CouponAAW\Domain\Profit\Money;
use DFX\CouponAAW\Domain\Profit\OrderSnapshot;

/**
 * Reads the orders behind the aggregates from WooCommerce Analytics (§6.1).
 *
 * Both lookup tables record `date_created` in the store's local time, so the
 * day boundaries below are local too and match what a shop owner means by
 * "yesterday".
 *
 * Refund rows are not read as orders: they carry a negative net total and a
 * parent order, and no coupon of their own, so on their own day they would
 * subtract revenue from a coupon-day the refund does not belong to. They are
 * folded into the order they refund instead, on the order's day: its revenue
 * less what was refunded, and its lines less the units that went back. A
 * refund re-queues the order's day, which is why aggregation replaces a day
 * whole rather than adding to it.
 *
 * That folding is not optional. The order's own row in the stats table keeps
 * the total it was placed for; WooCommerce records a partial refund as a row
 * of its own and never lowers the parent's. Reading only the parent counted a
 * refunded item as revenue for as long as the order stayed paid.
 */
final class WcOrderStatsRepository implements OrderStatsRepositoryInterface {

	/**
     * @var \wpdb
     * @readonly
     */
    private \wpdb $wpdb;
    /**
     * @var DateTimeZone
     * @readonly
     */
    private DateTimeZone $timezone;
    /**
     * @var int
     * @readonly
     */
    private int $decimals;
    /**
	 * Constructor.
	 *
	 * @param \wpdb        $wpdb     WordPress database handle.
	 * @param DateTimeZone $timezone The store's timezone.
	 * @param int          $decimals Places in the currency's minor unit.
	 */
	public function __construct(\wpdb $wpdb, DateTimeZone $timezone, int $decimals)
    {
        $this->wpdb = $wpdb;
        $this->timezone = $timezone;
        $this->decimals = $decimals;
    }

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
		$placeholders = self::placeholders( count( $statuses ) );

		/*
		 * The currency is joined rather than looked up. It used to come from
		 * wc_get_order() once per order, which is a full order object — the post
		 * or the HPOS row, plus its meta — built to read one column. On the shop
		 * this was written for the backfill walks about eleven hundred days at a
		 * couple of thousand coupon orders each, so that was millions of order
		 * loads, and a busy day loaded every one of its orders into memory at
		 * once.
		 *
		 * Where the column lives depends on how the shop stores orders, so there
		 * are two spellings of the same query and no third path: both were read
		 * out of the WooCommerce source rather than remembered. Each calls
		 * prepare() on its own literal rather than choosing a statement into a
		 * variable first — prepare() is typed to take a literal-string, and a
		 * string that has been through a variable is no longer one.
		 *
		 * Table names go through %i and every value through a placeholder. Only
		 * the IN list is interpolated, and what it interpolates is a run of %s
		 * built from a count — placeholders, not values. The sniff can follow
		 * neither that nor arguments passed through array_merge.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$prepared = $this->orders_have_their_own_table()
			? $wpdb->prepare(
				"SELECT c.order_id, c.coupon_id, c.discount_amount, s.date_created, s.net_total,
					o.currency AS currency
				FROM %i c
				INNER JOIN %i s ON s.order_id = c.order_id
				INNER JOIN %i o ON o.id = s.order_id
				WHERE s.date_created >= %s AND s.date_created < %s
					AND s.parent_id = 0
					AND s.status IN ({$placeholders})",
				array_merge(
					array( $this->coupon_lookup_table(), $this->order_stats_table(), $this->orders_table(), $from, $to ),
					$statuses
				)
			)
			: $wpdb->prepare(
				"SELECT c.order_id, c.coupon_id, c.discount_amount, s.date_created, s.net_total,
					m.meta_value AS currency
				FROM %i c
				INNER JOIN %i s ON s.order_id = c.order_id
				INNER JOIN %i p ON p.ID = s.order_id
				LEFT JOIN %i m ON m.post_id = s.order_id AND m.meta_key = '_order_currency'
				WHERE s.date_created >= %s AND s.date_created < %s
					AND s.parent_id = 0
					AND s.status IN ({$placeholders})",
				array_merge(
					array( $this->coupon_lookup_table(), $this->order_stats_table(), $wpdb->posts, $wpdb->postmeta, $from, $to ),
					$statuses
				)
			);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared just above, in whichever branch applies.
		$rows = $wpdb->get_results( $prepared, ARRAY_A );
		// phpcs:enable

		return $this->to_snapshots( (array) $rows, $from, $to );
	}

	/**
	 * The day of the earliest coupon order, bounding any backfill.
	 */
	public function earliest_coupon_order_day(): ?DateTimeImmutable {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$earliest = $wpdb->get_var(
			$wpdb->prepare(
				// Only rows that can be attributed to a coupon. A negative ID is
				// WooCommerce recording a coupon it could not identify, and
				// orders_on() drops those — so counting one here would start the
				// backfill at a day it is going to store nothing for, and walk
				// forward a scheduled step at a time until it reached a real one.
				'SELECT MIN(s.date_created)
				FROM %i c
				INNER JOIN %i s ON s.order_id = c.order_id
				WHERE c.coupon_id > 0',
				$this->coupon_lookup_table(),
				$this->order_stats_table()
			)
		);

		if ( ! is_string( $earliest ) || '' === $earliest || strncmp($earliest, '0000', strlen('0000')) === 0 ) {
			return null;
		}

		return ( new DateTimeImmutable( $earliest, $this->timezone ) )->setTime( 0, 0 );
	}

	/**
	 * Group the joined rows into one snapshot per order.
	 *
	 * @param array<int, mixed> $rows Rows of order-and-coupon, as the database returned them.
	 *
	 * @param string            $from  Start of the window, as stored.
	 * @param string            $to    End of the window, exclusive.
	 *
	 * @return list<OrderSnapshot>
	 */
	private function to_snapshots( array $rows, string $from, string $to ): array {
		$orders = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$order_id  = (int) ( $row['order_id'] ?? 0 );
			$coupon_id = (int) ( $row['coupon_id'] ?? 0 );

			if ( $order_id < 1 ) {
				continue;
			}

			/*
			 * A coupon ID below one is WooCommerce saying it does not know which
			 * coupon this was. Its own comment in Reports\Coupons\DataStore reads
			 * "Insert a unique, but obviously invalid ID for this deleted coupon",
			 * and the value it writes is -1, -2 and so on, numbered per order.
			 *
			 * It reaches that path when the line carries neither `coupon_info`
			 * (which WooCommerce only began writing in 8.7) nor the older
			 * `coupon_data`, and the code no longer resolves to a coupon — an old
			 * order for a coupon that has since been deleted. Any shop that has
			 * ever deleted a coupon has these rows.
			 *
			 * They are dropped rather than carried, because there is nothing they
			 * could be attributed to. The IDs are unique *per order*, so the -1 on
			 * one order and the -1 on another are different coupons; summing them
			 * would invent a coupon that never existed and report its margin. An
			 * order keeps its other coupons, and an order whose coupons have all
			 * been deleted contributes nothing, which is the honest answer.
			 */
			if ( $coupon_id < 1 ) {
				continue;
			}

			if ( ! isset( $orders[ $order_id ] ) ) {
				$currency = $this->currency_of( $row );

				$orders[ $order_id ] = array(
					'date'      => new DateTimeImmutable( (string) ( $row['date_created'] ?? 'now' ), $this->timezone ),
					'revenue'   => Money::from_decimal( (float) ( $row['net_total'] ?? 0 ), $currency, $this->decimals ),
					'currency'  => $currency,
					'discounts' => array(),
				);
			}

			$orders[ $order_id ]['discounts'][ $coupon_id ] = Money::from_decimal(
				(float) ( $row['discount_amount'] ?? 0 ),
				$orders[ $order_id ]['currency'],
				$this->decimals
			);
		}

		$line_items = $this->line_items_on( $from, $to );
		$refunded   = $this->refunded_revenue_on( $from, $to );
		$units      = $this->refunded_units_on( $from, $to );
		$snapshots  = array();

		foreach ( $orders as $order_id => $order ) {
			$revenue = $order['revenue'];

			if ( isset( $refunded[ $order_id ] ) ) {
				// The refund rows' net total is negative, so this is a plus.
				$revenue = $revenue->plus( Money::from_decimal( $refunded[ $order_id ], $order['currency'], $this->decimals ) );
			}

			$snapshots[] = new OrderSnapshot(
				$order_id,
				$order['date'],
				$revenue,
				$line_items[ $order_id ] ?? array(),
				$order['discounts'],
				$units[ $order_id ] ?? array()
			);
		}

		return $snapshots;
	}

	/**
	 * What was refunded on each countable coupon order of a day, as the refunds'
	 * own net total: negative, and excluding tax and shipping, so that it can be
	 * added straight onto the order's.
	 *
	 * A refund counts once WooCommerce has written its stats row, and not
	 * before. The units below are read through the same row, so that a refund
	 * is never half applied, its goods taken off the cost while its money was
	 * still counted as revenue. A refund analytics has yet to see is picked up
	 * when it does: that write re-queues the order's day.
	 *
	 * The refunds are found through the order storage rather than through the
	 * stats table's `parent_id`, which has no index of its own; the storage's
	 * parent column has one in both spellings.
	 *
	 * @param string $from Start of the window, as stored.
	 * @param string $to   End of the window, exclusive.
	 *
	 * @return array<int, float> Refunded net total by order ID.
	 */
	private function refunded_revenue_on( string $from, string $to ): array {
		$wpdb = $this->wpdb;

		$statuses     = $this->countable_statuses();
		$placeholders = self::placeholders( count( $statuses ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$prepared = $this->orders_have_their_own_table()
			? $wpdb->prepare(
				"SELECT r.parent_order_id AS order_id, SUM(rs.net_total) AS refunded
				FROM %i r
				INNER JOIN %i s ON s.order_id = r.parent_order_id
				INNER JOIN %i rs ON rs.order_id = r.id
				WHERE r.type = 'shop_order_refund'
					AND s.date_created >= %s AND s.date_created < %s
					AND s.parent_id = 0
					AND s.status IN ({$placeholders})
				GROUP BY r.parent_order_id",
				array_merge(
					array( $this->orders_table(), $this->order_stats_table(), $this->order_stats_table(), $from, $to ),
					$statuses
				)
			)
			: $wpdb->prepare(
				"SELECT r.post_parent AS order_id, SUM(rs.net_total) AS refunded
				FROM %i r
				INNER JOIN %i s ON s.order_id = r.post_parent
				INNER JOIN %i rs ON rs.order_id = r.ID
				WHERE r.post_type = 'shop_order_refund'
					AND s.date_created >= %s AND s.date_created < %s
					AND s.parent_id = 0
					AND s.status IN ({$placeholders})
				GROUP BY r.post_parent",
				array_merge(
					array( $wpdb->posts, $this->order_stats_table(), $this->order_stats_table(), $from, $to ),
					$statuses
				)
			);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared just above, in whichever branch applies.
		$rows = $wpdb->get_results( $prepared, ARRAY_A );
		// phpcs:enable

		$refunded = array();

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$refunded[ (int) ( $row['order_id'] ?? 0 ) ] = (float) ( $row['refunded'] ?? 0 );
		}

		return $refunded;
	}

	/**
	 * The units refunded against each line of a day's countable coupon orders,
	 * beside the units the line was ordered with.
	 *
	 * A refund line points back at the line it refunds through
	 * `_refunded_item_id`, and records its quantity as a negative `_qty`. A
	 * refund of money alone has no lines at all, and leaves every unit kept.
	 *
	 * Read through the refund's stats row for the reason given on
	 * refunded_revenue_on(). The original line is joined on a cast of the
	 * pointer, because the meta value is a string and comparing it as one would
	 * keep the item meta index from being used.
	 *
	 * @param string $from Start of the window, as stored.
	 * @param string $to   End of the window, exclusive.
	 *
	 * @return array<int, array<int, array{ordered: int, refunded: int}>> By order ID, then line item ID.
	 */
	private function refunded_units_on( string $from, string $to ): array {
		$wpdb = $this->wpdb;

		$statuses     = $this->countable_statuses();
		$placeholders = self::placeholders( count( $statuses ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$prepared = $this->orders_have_their_own_table()
			? $wpdb->prepare(
				"SELECT r.parent_order_id AS order_id, ri.meta_value AS line_id,
					SUM(rq.meta_value) AS refunded, MAX(oq.meta_value) AS ordered
				FROM %i r
				INNER JOIN %i s ON s.order_id = r.parent_order_id
				INNER JOIN %i rs ON rs.order_id = r.id
				INNER JOIN %i it ON it.order_id = r.id AND it.order_item_type = 'line_item'
				INNER JOIN %i ri ON ri.order_item_id = it.order_item_id AND ri.meta_key = '_refunded_item_id'
				INNER JOIN %i rq ON rq.order_item_id = it.order_item_id AND rq.meta_key = '_qty'
				INNER JOIN %i oq ON oq.order_item_id = CAST(ri.meta_value AS UNSIGNED) AND oq.meta_key = '_qty'
				WHERE r.type = 'shop_order_refund'
					AND s.date_created >= %s AND s.date_created < %s
					AND s.parent_id = 0
					AND s.status IN ({$placeholders})
				GROUP BY r.parent_order_id, ri.meta_value",
				array_merge(
					array(
						$this->orders_table(),
						$this->order_stats_table(),
						$this->order_stats_table(),
						$wpdb->prefix . 'woocommerce_order_items',
						$wpdb->prefix . 'woocommerce_order_itemmeta',
						$wpdb->prefix . 'woocommerce_order_itemmeta',
						$wpdb->prefix . 'woocommerce_order_itemmeta',
						$from,
						$to,
					),
					$statuses
				)
			)
			: $wpdb->prepare(
				"SELECT r.post_parent AS order_id, ri.meta_value AS line_id,
					SUM(rq.meta_value) AS refunded, MAX(oq.meta_value) AS ordered
				FROM %i r
				INNER JOIN %i s ON s.order_id = r.post_parent
				INNER JOIN %i rs ON rs.order_id = r.ID
				INNER JOIN %i it ON it.order_id = r.ID AND it.order_item_type = 'line_item'
				INNER JOIN %i ri ON ri.order_item_id = it.order_item_id AND ri.meta_key = '_refunded_item_id'
				INNER JOIN %i rq ON rq.order_item_id = it.order_item_id AND rq.meta_key = '_qty'
				INNER JOIN %i oq ON oq.order_item_id = CAST(ri.meta_value AS UNSIGNED) AND oq.meta_key = '_qty'
				WHERE r.post_type = 'shop_order_refund'
					AND s.date_created >= %s AND s.date_created < %s
					AND s.parent_id = 0
					AND s.status IN ({$placeholders})
				GROUP BY r.post_parent, ri.meta_value",
				array_merge(
					array(
						$wpdb->posts,
						$this->order_stats_table(),
						$this->order_stats_table(),
						$wpdb->prefix . 'woocommerce_order_items',
						$wpdb->prefix . 'woocommerce_order_itemmeta',
						$wpdb->prefix . 'woocommerce_order_itemmeta',
						$wpdb->prefix . 'woocommerce_order_itemmeta',
						$from,
						$to,
					),
					$statuses
				)
			);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared just above, in whichever branch applies.
		$rows = $wpdb->get_results( $prepared, ARRAY_A );
		// phpcs:enable

		$units = array();

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$line_id = (int) ( $row['line_id'] ?? 0 );

			if ( $line_id < 1 ) {
				continue;
			}

			$units[ (int) ( $row['order_id'] ?? 0 ) ][ $line_id ] = array(
				'ordered'  => (int) ( $row['ordered'] ?? 0 ),
				'refunded' => (int) abs( (float) ( $row['refunded'] ?? 0 ) ),
			);
		}

		return $units;
	}

	/**
	 * The line item IDs of every countable coupon order on a day.
	 *
	 * Joined by the same window rather than by an IN list of the day's orders.
	 * A busy day at the shop this was written for is tens of thousands of
	 * orders, and a prepared statement with a placeholder each is the thing this
	 * project's performance rules name outright — the query grows with the
	 * shop's best day, which is when it is least welcome.
	 *
	 * Only `line_item` rows: fees, shipping and tax lines are not goods and have
	 * no cost of their own, and counting them would put the coverage figure out.
	 *
	 * @param string $from Start of the window, as stored.
	 * @param string $to   End of the window, exclusive.
	 *
	 * @return array<int, list<int>> Line item IDs by order ID.
	 */
	private function line_items_on( string $from, string $to ): array {
		$wpdb = $this->wpdb;

		$statuses     = $this->countable_statuses();
		$placeholders = self::placeholders( count( $statuses ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.order_id, i.order_item_id
				FROM %i i
				INNER JOIN %i s ON s.order_id = i.order_id
				WHERE i.order_item_type = 'line_item'
					AND s.date_created >= %s AND s.date_created < %s
					AND s.parent_id = 0
					AND s.status IN ({$placeholders})",
				array_merge(
					array( $wpdb->prefix . 'woocommerce_order_items', $this->order_stats_table(), $from, $to ),
					$statuses
				)
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
	 * The currency of an order, from the row already read.
	 *
	 * Empty means the shop's own currency, which is what WooCommerce does with
	 * it: `OrdersTableDataStore` falls back to `get_woocommerce_currency()` when
	 * the stored value is empty. Orders placed before WooCommerce recorded a
	 * currency at all have nothing stored, and they used to be dropped from the
	 * aggregates entirely — a shop that predates the field saw a hole in its
	 * history rather than a currency.
	 *
	 * @param array<string, mixed> $row One row of the day's read.
	 */
	private function currency_of( array $row ): string {
		$currency = (string) ( $row['currency'] ?? '' );

		return '' === $currency ? $this->store_currency() : $currency;
	}

	/**
	 * The shop's own currency, asked once per request.
	 */
	private function store_currency(): string {
		static $currency = null;

		if ( null === $currency ) {
			$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		}

		return $currency;
	}

	/**
	 * Whether orders live in WooCommerce's own tables rather than as posts.
	 *
	 * Asked of WooCommerce rather than of the database. A shop mid-migration has
	 * both, and which one is authoritative is WooCommerce's answer to give.
	 */
	private function orders_have_their_own_table(): bool {
		return class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * WooCommerce's own orders table.
	 */
	private function orders_table(): string {
		return $this->wpdb->prefix . 'wc_orders';
	}

	/**
	 * A run of `%s` placeholders, as a literal.
	 *
	 * Concatenated from literals rather than built with `implode()`, which
	 * returns a plain string: `prepare()` is typed to take a literal-string, and
	 * that type is the whole reason a generated fragment cannot quietly become a
	 * generated *value*. What varies here is how many placeholders there are,
	 * never what is in them.
	 *
	 * @param int $count How many.
	 *
	 * @return literal-string
	 */
	private static function placeholders( int $count ): string {
		if ( $count < 1 ) {
			return '';
		}

		$list = '%s';

		for ( $i = $count; $i > 1; $i-- ) {
			$list .= ', %s';
		}

		return $list;
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
					=> strncmp($status, 'wc-', strlen('wc-')) === 0 ? $status : 'wc-' . $status,
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
