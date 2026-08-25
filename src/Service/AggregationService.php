<?php
/**
 * Turning orders into aggregates.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Service;

use DateTimeImmutable;
use DFX\CouponAAW\Cost\CostSourceInterface;
use DFX\CouponAAW\Cost\CostSourceRegistry;
use DFX\CouponAAW\Domain\Coupon\CouponId;
use DFX\CouponAAW\Domain\Profit\CouponDayStats;
use DFX\CouponAAW\Domain\Profit\Money;
use DFX\CouponAAW\Domain\Profit\OrderSnapshot;
use DFX\CouponAAW\Repository\CouponStatsRepositoryInterface;
use DFX\CouponAAW\Repository\OrderStatsRepositoryInterface;

/**
 * Builds the rows the margin screen reads (§6.2).
 *
 * A day is always recomputed whole and its previous rows discarded. That is
 * what makes the job safe to re-run, and it has to be: an order is refunded, an
 * order is edited, a backfill overlaps work the incremental job already did.
 * Adding to existing rows instead would double-count in every one of those
 * cases, and the error would be invisible.
 */
final class AggregationService implements AggregationInterface {

	/**
	 * Constructor.
	 *
	 * @param OrderStatsRepositoryInterface  $orders Source of orders.
	 * @param CouponStatsRepositoryInterface $stats  Where aggregates are written.
	 * @param CostSourceRegistry             $costs  Selects the one cost system to read.
	 */
	public function __construct(
		private readonly OrderStatsRepositoryInterface $orders,
		private readonly CouponStatsRepositoryInterface $stats,
		private readonly CostSourceRegistry $costs
	) {}

	/**
	 * Recompute one day, replacing whatever was stored for it.
	 *
	 * @param DateTimeImmutable $day The day to aggregate.
	 *
	 * @return int How many rows the day now holds.
	 */
	public function aggregate_day( DateTimeImmutable $day ): int {
		$source  = $this->costs->active();
		$orders  = $this->orders->orders_on( $day );
		$buckets = array();

		if ( null !== $source ) {
			$source->prime( $this->order_ids( $orders ), $this->line_item_ids( $orders ) );
		}

		foreach ( $orders as $order ) {
			$this->add_order( $buckets, $order, $source );
		}

		$rows = array_values( array_map( fn ( array $bucket ): CouponDayStats => $this->to_row( $bucket, $day ), $buckets ) );

		$this->stats->replace_day( $day, $rows, null === $source ? '' : $source->get_identifier() );

		return count( $rows );
	}

	/**
	 * The IDs of a day's orders.
	 *
	 * @param list<OrderSnapshot> $orders The day's orders.
	 *
	 * @return list<int>
	 */
	private function order_ids( array $orders ): array {
		return array_values( array_map( static fn ( OrderSnapshot $order ): int => $order->id, $orders ) );
	}

	/**
	 * Every line of a day's orders.
	 *
	 * @param list<OrderSnapshot> $orders The day's orders.
	 *
	 * @return list<int>
	 */
	private function line_item_ids( array $orders ): array {
		$ids = array();

		foreach ( $orders as $order ) {
			foreach ( $order->line_item_ids as $line_item_id ) {
				$ids[] = $line_item_id;
			}
		}

		return $ids;
	}

	/**
	 * Fold one order into the day's buckets.
	 *
	 * Its full revenue counts against every coupon used on it. A coupon's row
	 * answers "what did orders using this coupon earn", which is the question
	 * being asked and what WooCommerce's own coupon report answers. Summing rows
	 * across coupons therefore double-counts a multi-coupon order: the figure is
	 * per coupon, not a partition of revenue, and a screen must not present it as
	 * one. Splitting proportionally was the alternative and would attribute to
	 * each coupon a number no order ever earned.
	 *
	 * @param array<string, array<string, mixed>> $buckets Accumulator, by reference.
	 * @param OrderSnapshot                       $order   The order to fold in.
	 * @param CostSourceInterface|null            $source  The active cost system, if any.
	 */
	private function add_order( array &$buckets, OrderSnapshot $order, ?CostSourceInterface $source ): void {
		[ $cost, $covered ] = $this->cost_of( $order, $source );

		foreach ( $order->coupon_discounts as $coupon_id => $discount ) {
			$key = $coupon_id . ':' . $order->currency();

			if ( ! isset( $buckets[ $key ] ) ) {
				$buckets[ $key ] = array(
					'coupon_id'     => new CouponId( (int) $coupon_id ),
					'orders'        => 0,
					'net_revenue'   => Money::zero( $order->currency() ),
					'discount'      => Money::zero( $order->currency() ),
					'cost'          => Money::zero( $order->currency() ),
					'covered_lines' => 0,
					'total_lines'   => 0,
				);
			}

			++$buckets[ $key ]['orders'];
			$buckets[ $key ]['net_revenue']    = $buckets[ $key ]['net_revenue']->plus( $order->net_revenue );
			$buckets[ $key ]['discount']       = $buckets[ $key ]['discount']->plus( $discount );
			$buckets[ $key ]['cost']           = $buckets[ $key ]['cost']->plus( $cost );
			$buckets[ $key ]['covered_lines'] += $covered;
			$buckets[ $key ]['total_lines']   += count( $order->line_item_ids );
		}
	}

	/**
	 * What an order's goods cost, and how many of its lines that figure covers.
	 *
	 * A line the active source has no figure for is counted but not guessed at.
	 * That count is the whole basis of §6.3's coverage, and filling it in from
	 * another cost system would make the coverage figure meaningless.
	 *
	 * @param OrderSnapshot            $order  The order.
	 * @param CostSourceInterface|null $source The active cost system, if any.
	 *
	 * @return array{0: Money, 1: int}
	 */
	private function cost_of( OrderSnapshot $order, ?CostSourceInterface $source ): array {
		$cost    = Money::zero( $order->currency() );
		$covered = 0;

		if ( null === $source ) {
			return array( $cost, $covered );
		}

		foreach ( $order->line_item_ids as $line_item_id ) {
			$line_cost = $source->get_line_cost( $order->id, $line_item_id );

			if ( null === $line_cost ) {
				continue;
			}

			$cost = $cost->plus( $line_cost );
			++$covered;
		}

		return array( $cost, $covered );
	}

	/**
	 * Turn one accumulated bucket into a stored row.
	 *
	 * @param array<string, mixed> $bucket The accumulated figures.
	 * @param DateTimeImmutable    $day    The day being aggregated.
	 */
	private function to_row( array $bucket, DateTimeImmutable $day ): CouponDayStats {
		return new CouponDayStats(
			$bucket['coupon_id'],
			$day,
			$bucket['orders'],
			$bucket['net_revenue'],
			$bucket['discount'],
			$bucket['cost'],
			$bucket['covered_lines'],
			$bucket['total_lines']
		);
	}
}
