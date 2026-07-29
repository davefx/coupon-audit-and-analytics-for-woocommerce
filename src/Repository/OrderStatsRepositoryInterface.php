<?php
/**
 * Order statistics contract.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Repository;

use DateTimeImmutable;
use DFX\CouponAAW\Domain\Profit\OrderSnapshot;

/**
 * Reads the orders behind the aggregates (§6.1).
 */
interface OrderStatsRepositoryInterface {

	/**
	 * Every countable order placed on a given day that used at least one coupon.
	 *
	 * @param DateTimeImmutable $day The day, in the store's timezone.
	 *
	 * @return list<OrderSnapshot>
	 */
	public function orders_on( DateTimeImmutable $day ): array;

	/**
	 * The day of the earliest order that used a coupon, or null if there is none.
	 *
	 * Bounds the backfill: there is nothing to aggregate before it.
	 */
	public function earliest_coupon_order_day(): ?DateTimeImmutable;
}
