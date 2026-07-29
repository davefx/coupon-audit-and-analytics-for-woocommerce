<?php
/**
 * Margin orchestration.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Service;

use DateTimeImmutable;
use DFX\CouponAAW\Domain\Clock\ClockInterface;
use DFX\CouponAAW\Domain\Coupon\CouponId;
use DFX\CouponAAW\Domain\Profit\CouponDayStats;
use DFX\CouponAAW\Domain\Profit\CouponMargin;
use DFX\CouponAAW\Domain\Profit\Money;
use DFX\CouponAAW\Licensing\Feature;
use DFX\CouponAAW\Licensing\FeatureGateInterface;
use DFX\CouponAAW\Repository\CouponRepositoryInterface;
use DFX\CouponAAW\Repository\CouponStatsRepositoryInterface;

/**
 * Sums the daily aggregates into per-coupon margins (§6.2).
 *
 * The window is capped at thirty days unless the store may see more. §11 asks
 * that the free limit be a natural consequence of the process rather than a
 * cosmetic filter, and it is: only the trailing thirty days are ever summed, so
 * there is no longer figure sitting behind a hidden flag.
 */
final class MarginService {

	/**
	 * The window the free tier covers.
	 */
	public const FREE_WINDOW_DAYS = 30;

	/**
	 * Constructor.
	 *
	 * @param CouponStatsRepositoryInterface $stats   Source of daily figures.
	 * @param CouponRepositoryInterface      $coupons Supplies coupon codes.
	 * @param ClockInterface                 $clock   Supplies today.
	 * @param FeatureGateInterface           $gate    Decides how far back to look.
	 */
	public function __construct(
		private readonly CouponStatsRepositoryInterface $stats,
		private readonly CouponRepositoryInterface $coupons,
		private readonly ClockInterface $clock,
		private readonly FeatureGateInterface $gate
	) {}

	/**
	 * How many days back the store may look.
	 */
	public function window_days(): int {
		return $this->gate->allows( Feature::FULL_HISTORY ) ? 365 : self::FREE_WINDOW_DAYS;
	}

	/**
	 * Per-coupon margins over the window, largest revenue first.
	 *
	 * @return list<CouponMargin>
	 */
	public function margins(): array {
		$to   = $this->clock->now()->setTime( 0, 0 );
		$from = $to->modify( '-' . ( $this->window_days() - 1 ) . ' days' );

		$buckets = array();

		foreach ( $this->stats->between( $from, $to ) as $row ) {
			$this->add( $buckets, $row );
		}

		$margins = array();

		foreach ( $buckets as $bucket ) {
			$margins[] = $this->to_margin( $bucket );
		}

		usort(
			$margins,
			static fn ( CouponMargin $a, CouponMargin $b ): int
				=> $b->net_revenue->amount <=> $a->net_revenue->amount
		);

		return $margins;
	}

	/**
	 * The first and last day of the window.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
	 */
	public function window(): array {
		$to = $this->clock->now()->setTime( 0, 0 );

		return array( $to->modify( '-' . ( $this->window_days() - 1 ) . ' days' ), $to );
	}

	/**
	 * Fold one day's row into the running totals.
	 *
	 * Keyed by coupon and currency together, so a store selling in two never
	 * ends up with one line adding the two together (§8.5).
	 *
	 * @param array<string, array<string, mixed>> $buckets Accumulator, by reference.
	 * @param CouponDayStats                      $row     The day's figures.
	 */
	private function add( array &$buckets, CouponDayStats $row ): void {
		$key = $row->coupon_id->value . ':' . $row->currency();

		if ( ! isset( $buckets[ $key ] ) ) {
			$buckets[ $key ] = array(
				'coupon_id'     => $row->coupon_id,
				'orders'        => 0,
				'net_revenue'   => Money::zero( $row->currency() ),
				'discount'      => Money::zero( $row->currency() ),
				'cost'          => Money::zero( $row->currency() ),
				'covered_lines' => 0,
				'total_lines'   => 0,
			);
		}

		$buckets[ $key ]['orders']        += $row->orders;
		$buckets[ $key ]['net_revenue']    = $buckets[ $key ]['net_revenue']->plus( $row->net_revenue );
		$buckets[ $key ]['discount']       = $buckets[ $key ]['discount']->plus( $row->discount );
		$buckets[ $key ]['cost']           = $buckets[ $key ]['cost']->plus( $row->cost );
		$buckets[ $key ]['covered_lines'] += $row->covered_lines;
		$buckets[ $key ]['total_lines']   += $row->total_lines;
	}

	/**
	 * Turn accumulated totals into a margin, naming the coupon.
	 *
	 * A coupon deleted since its orders were placed has no code, and the null
	 * says exactly that. Phrasing it for a reader is the admin layer's job: a
	 * translated string here would put WordPress in a service, which §5 forbids
	 * and which the unit suite catches immediately.
	 *
	 * @param array<string, mixed> $bucket The accumulated figures.
	 */
	private function to_margin( array $bucket ): CouponMargin {
		$id     = $bucket['coupon_id'];
		$coupon = $this->coupons->find( $id );

		return new CouponMargin(
			$id,
			$coupon?->code,
			$bucket['orders'],
			$bucket['net_revenue'],
			$bucket['discount'],
			$bucket['cost'],
			$bucket['covered_lines'],
			$bucket['total_lines']
		);
	}
}
