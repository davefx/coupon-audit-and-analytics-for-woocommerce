<?php
/**
 * In-memory order statistics.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Fixtures;

use DateTimeImmutable;
use DFX\CouponAAW\Domain\Profit\OrderSnapshot;
use DFX\CouponAAW\Repository\OrderStatsRepositoryInterface;

/**
 * Lets the aggregator be tested without a database.
 */
final class InMemoryOrderStatsRepository implements OrderStatsRepositoryInterface {

	/**
	 * Constructor.
	 *
	 * @param list<OrderSnapshot> $orders The stored orders.
	 */
	public function __construct( private readonly array $orders = array() ) {}

	/**
	 * Orders placed on a given day.
	 *
	 * @param DateTimeImmutable $day The day.
	 *
	 * @return list<OrderSnapshot>
	 */
	public function orders_on( DateTimeImmutable $day ): array {
		return array_values(
			array_filter(
				$this->orders,
				static fn ( OrderSnapshot $order ): bool => $order->day() === $day->format( 'Y-m-d' )
			)
		);
	}

	/**
	 * The earliest day with a coupon order.
	 */
	public function earliest_coupon_order_day(): ?DateTimeImmutable {
		$earliest = null;

		foreach ( $this->orders as $order ) {
			if ( null === $earliest || $order->date < $earliest ) {
				$earliest = $order->date;
			}
		}

		return $earliest;
	}
}
