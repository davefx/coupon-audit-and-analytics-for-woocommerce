<?php
/**
 * One order, as aggregation needs it.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Profit;

use DateTimeImmutable;
use DFX\CouponAAW\Domain\Coupon\CouponId;

/**
 * The parts of an order the aggregator reads: when it happened, what it earned,
 * which coupons were used and what each gave away, and which lines it holds.
 *
 * Line costs are deliberately absent. They come from whichever cost system the
 * store uses, which the repository knows nothing about, so the aggregator asks
 * the active source for them by line.
 */
final class OrderSnapshot {

	/**
	 * Constructor.
	 *
	 * @param int               $id               The order ID.
	 * @param DateTimeImmutable $date             When the order was placed, in the store's timezone.
	 * @param Money             $net_revenue      Revenue after discount, excluding tax and shipping.
	 * @param list<int>         $line_item_ids    The order's line items.
	 * @param array<int, Money> $coupon_discounts What each coupon gave away, keyed by coupon ID.
	 */
	public function __construct(
		public readonly int $id,
		public readonly DateTimeImmutable $date,
		public readonly Money $net_revenue,
		public readonly array $line_item_ids,
		public readonly array $coupon_discounts
	) {}

	/**
	 * The day this order belongs to.
	 */
	public function day(): string {
		return $this->date->format( 'Y-m-d' );
	}

	/**
	 * The currency every amount on this order is in.
	 */
	public function currency(): string {
		return $this->net_revenue->currency;
	}

	/**
	 * The coupons used, as identifiers.
	 *
	 * @return list<CouponId>
	 */
	public function coupons(): array {
		return array_values(
			array_map(
				static fn ( int $id ): CouponId => new CouponId( $id ),
				array_keys( $this->coupon_discounts )
			)
		);
	}
}
