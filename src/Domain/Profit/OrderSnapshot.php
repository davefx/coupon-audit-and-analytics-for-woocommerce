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
     * @var int
     * @readonly
     */
    public int $id;
    /**
     * @var DateTimeImmutable
     * @readonly
     */
    public DateTimeImmutable $date;
    /**
     * @var Money
     * @readonly
     */
    public Money $net_revenue;
    /**
     * @var list<int>
     * @readonly
     */
    public array $line_item_ids;
    /**
     * @var array<int, Money>
     * @readonly
     */
    public array $coupon_discounts;
    /**
     * @var array<int, array{ordered: int, refunded: int}>
     * @readonly
     */
    public array $refunded_units = array();
    /**
	 * Constructor.
	 *
	 * A refund is folded in rather than carried separately. `$net_revenue` is
	 * what the order earned once its refunds are taken off, and
	 * `$refunded_units` says how many units of each line went back, so that the
	 * cost counted is the cost of what the customer kept. A refund of money with
	 * no units against it lowers the revenue and leaves the cost alone, which is
	 * what happened: the shop gave money back and the goods stayed sold.
	 *
	 * @param int                                            $id               The order ID.
	 * @param DateTimeImmutable                              $date             When the order was placed, in the store's timezone.
	 * @param Money                                          $net_revenue      Revenue after discount and refunds, excluding tax and shipping.
	 * @param list<int>                                      $line_item_ids    The order's line items.
	 * @param array<int, Money>                              $coupon_discounts What each coupon gave away, keyed by coupon ID.
	 * @param array<int, array{ordered: int, refunded: int}> $refunded_units  Units ordered and refunded, keyed by line item ID, for lines with a refund.
	 */
	public function __construct(int $id, DateTimeImmutable $date, Money $net_revenue, array $line_item_ids, array $coupon_discounts, array $refunded_units = array())
    {
        $this->id = $id;
        $this->date = $date;
        $this->net_revenue = $net_revenue;
        $this->line_item_ids = $line_item_ids;
        $this->coupon_discounts = $coupon_discounts;
        $this->refunded_units = $refunded_units;
    }

	/**
	 * How many units of a line the customer kept, out of how many were ordered.
	 *
	 * A line nothing was refunded against keeps everything, reported as one of
	 * one so that a caller can take the share without knowing the quantity.
	 *
	 * @param int $line_item_id The line.
	 *
	 * @return array{0: int, 1: int} Units kept, and units ordered.
	 */
	public function kept_units( int $line_item_id ): array {
		if ( ! isset( $this->refunded_units[ $line_item_id ] ) ) {
			return array( 1, 1 );
		}

		$ordered = $this->refunded_units[ $line_item_id ]['ordered'];

		return array( max( 0, $ordered - $this->refunded_units[ $line_item_id ]['refunded'] ), $ordered );
	}

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
