<?php
/**
 * In-memory aggregates storage.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Fixtures;

use DateTimeImmutable;
use DFX\CouponAAW\Domain\Profit\CouponDayStats;
use DFX\CouponAAW\Repository\CouponStatsRepositoryInterface;

/**
 * Records what the aggregator wrote, so tests can assert on it.
 */
final class InMemoryCouponStatsRepository implements CouponStatsRepositoryInterface {

	/**
	 * Stored rows, keyed by day.
	 *
	 * @var array<string, list<CouponDayStats>>
	 */
	private array $days = array();

	/**
	 * The cost source recorded against the last write.
	 *
	 * @var string
	 */
	public string $last_cost_source = '';

	/**
	 * How many times a day has been replaced.
	 *
	 * @var int
	 */
	public int $writes = 0;

	/**
	 * Replace one day.
	 *
	 * @param DateTimeImmutable    $day         The day.
	 * @param list<CouponDayStats> $rows        The figures.
	 * @param string               $cost_source Which system produced the costs.
	 */
	public function replace_day( DateTimeImmutable $day, array $rows, string $cost_source ): void {
		++$this->writes;

		$this->days[ $day->format( 'Y-m-d' ) ] = $rows;
		$this->last_cost_source                = $cost_source;
	}

	/**
	 * Everything stored for one day.
	 *
	 * @param DateTimeImmutable $day The day.
	 *
	 * @return list<CouponDayStats>
	 */
	public function for_day( DateTimeImmutable $day ): array {
		return $this->days[ $day->format( 'Y-m-d' ) ] ?? array();
	}

	/**
	 * Everything stored between two days.
	 *
	 * @param DateTimeImmutable $from First day.
	 * @param DateTimeImmutable $to   Last day.
	 *
	 * @return list<CouponDayStats>
	 */
	public function between( DateTimeImmutable $from, DateTimeImmutable $to ): array {
		$found = array();

		foreach ( $this->days as $day => $rows ) {
			if ( $day >= $from->format( 'Y-m-d' ) && $day <= $to->format( 'Y-m-d' ) ) {
				$found = array_merge( $found, $rows );
			}
		}

		return $found;
	}
}
