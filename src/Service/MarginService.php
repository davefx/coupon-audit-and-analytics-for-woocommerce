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
use Generator;
use DFX\CouponAAW\Domain\Profit\CouponTotals;
use DFX\CouponAAW\Domain\Profit\CouponMargin;
use DFX\CouponAAW\Domain\Profit\Money;
use DFX\CouponAAW\Repository\CouponRepositoryInterface;
use DFX\CouponAAW\Repository\CouponStatsRepositoryInterface;

/**
 * Sums the daily aggregates into per-coupon margins (§6.2).
 *
 * The window is thirty days, and only those thirty days are ever summed — there
 * is no longer figure computed and then hidden. How many days it is comes in
 * through the constructor, so this class has no opinion about where the number
 * was decided and no way to be told a different one twice.
 */
final class MarginService {

	/**
     * @var CouponStatsRepositoryInterface
     * @readonly
     */
    private CouponStatsRepositoryInterface $stats;
    /**
     * @var CouponRepositoryInterface
     * @readonly
     */
    private CouponRepositoryInterface $coupons;
    /**
     * @var ClockInterface
     * @readonly
     */
    private ClockInterface $clock;
    /**
     * @var int
     * @readonly
     */
    private int $window_days = self::WINDOW_DAYS;
    /**
	 * How many lines an export reads at a time.
	 *
	 * Each is a coupon and a currency, so a chunk is also how many coupon codes
	 * are read in one go. Five hundred keeps both to one query without holding a
	 * year of the window in memory.
	 */
	public const STREAM_CHUNK = 500;

	/**
	 * How far back the margin screen looks unless something says otherwise.
	 *
	 * A default, not an allowance. `dfxcaaw_margin_window_days` changes it, and
	 * anything may use that filter — a snippet in a theme, another plugin, a
	 * reporting period chosen elsewhere.
	 */
	public const WINDOW_DAYS = 30;

	/**
	 * Constructor.
	 *
	 * @param CouponStatsRepositoryInterface $stats   Source of daily figures.
	 * @param CouponRepositoryInterface      $coupons Supplies coupon codes.
	 * @param ClockInterface                 $clock       Supplies today.
	 * @param int                            $window_days How far back to look.
	 */
	public function __construct(CouponStatsRepositoryInterface $stats, CouponRepositoryInterface $coupons, ClockInterface $clock, int $window_days = self::WINDOW_DAYS)
    {
        $this->stats = $stats;
        $this->coupons = $coupons;
        $this->clock = $clock;
        $this->window_days = $window_days;
    }

	/**
	 * How many days back the screen looks.
	 */
	public function window_days(): int {
		return $this->window_days;
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
	 * One page of margins, largest revenue first.
	 *
	 * This is the read the screen makes. The window is summed by the database
	 * and one page comes back — where `margins()` reads every row of every day
	 * and adds them up here, which at a shop with twenty-six thousand coupons is
	 * hundreds of thousands of objects built in order to be added to a running
	 * total and discarded, and a year's window is millions of them.
	 *
	 * The codes are read in bulk for the page, not one at a time. Naming twenty
	 * coupons is twenty coupons; naming them inside the loop was a query each,
	 * which is the thing this file is not allowed to do.
	 *
	 * @param int $page     Which page, counting from one.
	 * @param int $per_page How many rows a page holds.
	 */
	public function page( int $page, int $per_page ): MarginPageResult {
		list( $from, $to ) = $this->window();

		$totals = $this->stats->totals_between( $from, $to, $per_page, max( 0, $page - 1 ) * $per_page );

		$coverage = $this->stats->coverage_between( $from, $to );

		return new MarginPageResult(
			$this->named( $totals ),
			$coverage['lines'],
			$coverage['with_cost']
		);
	}

	/**
	 * Every line of the window, a chunk at a time.
	 *
	 * What the CSV export wants. `margins()` reads every daily row in the window
	 * and totals them here; a year of the shop this was written for is millions
	 * of rows, each built into an object so it can be added to a running total
	 * and discarded. This asks the database for totals it has already summed,
	 * and holds one chunk at a time.
	 *
	 * @param int $chunk How many lines to read at once.
	 *
	 * @return Generator<int, CouponMargin>
	 */
	public function stream( int $chunk = self::STREAM_CHUNK ): Generator {
		list( $from, $to ) = $this->window();

		$size   = max( 1, $chunk );
		$offset = 0;

		while ( true ) {
			$totals = $this->stats->totals_between( $from, $to, $size, $offset );

			if ( array() === $totals ) {
				return;
			}

			foreach ( $this->named( $totals ) as $margin ) {
				yield $margin;
			}

			// A short chunk is the last one. A full chunk might be, and asking
			// once more is how that is found out — cheaper than the alternative,
			// which is a count that can disagree with the read that follows it.
			if ( count( $totals ) < $size ) {
				return;
			}

			$offset += $size;
		}
	}

	/**
	 * Turn totals into margins, naming the coupons in one read.
	 *
	 * @param list<CouponTotals> $totals The lines to name.
	 *
	 * @return list<CouponMargin>
	 */
	private function named( array $totals ): array {
		$codes   = $this->codes_for( $totals );
		$margins = array();

		foreach ( $totals as $total ) {
			$margins[] = new CouponMargin(
				$total->coupon_id,
				$codes[ $total->coupon_id->value ] ?? null,
				$total->orders,
				$total->net_revenue,
				$total->discount,
				$total->cost,
				$total->covered_lines,
				$total->total_lines
			);
		}

		return $margins;
	}

	/**
	 * The codes of the coupons on a page, where they still exist.
	 *
	 * Through `codes()` rather than `some()`: the only thing wanted is the name,
	 * and building a `CouponSnapshot` for it is a `WC_Coupon` per row.
	 *
	 * A coupon deleted since its orders were placed has no code, and its absence
	 * from this map says so. Phrasing that for a reader is the admin layer's
	 * job.
	 *
	 * @param list<CouponTotals> $totals The lines the page shows.
	 *
	 * @return array<int, string>
	 */
	private function codes_for( array $totals ): array {
		$ids = array();

		foreach ( $totals as $total ) {
			$ids[ $total->coupon_id->value ] = $total->coupon_id;
		}

		return $this->coupons->codes( array_values( $ids ) );
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
			($nullsafeVariable1 = $coupon) ? $nullsafeVariable1->code : null,
			$bucket['orders'],
			$bucket['net_revenue'],
			$bucket['discount'],
			$bucket['cost'],
			$bucket['covered_lines'],
			$bucket['total_lines']
		);
	}
}
