<?php
/**
 * Order snapshot tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Domain\Profit;

use DateTimeImmutable;
use DateTimeZone;
use DFX\CouponAAW\Domain\Coupon\CouponId;
use DFX\CouponAAW\Domain\Profit\Money;
use DFX\CouponAAW\Domain\Profit\OrderSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * One order, as the aggregator sees it.
 *
 * The day an order belongs to is the day in the store's timezone, not UTC. A
 * shop in Madrid selling at half past midnight has made a sale today, and an
 * order filed under yesterday would be aggregated into a day that has already
 * been written.
 */
final class OrderSnapshotTest extends TestCase {

	/**
	 * Build a snapshot.
	 *
	 * @param string            $date      When the order was placed.
	 * @param array<int, Money> $discounts What each coupon gave away.
	 */
	private function order( string $date = '2026-07-01 10:00', array $discounts = array() ): OrderSnapshot {
		return new OrderSnapshot(
			42,
			new DateTimeImmutable( $date, new DateTimeZone( 'Europe/Madrid' ) ),
			new Money( 10000, 'EUR' ),
			array( 1, 2, 3 ),
			$discounts
		);
	}

	/**
	 * The day is the calendar day of the order's own timezone.
	 */
	public function test_the_day_is_the_calendar_day_it_was_placed(): void {
		$this->assertSame( '2026-07-01', $this->order( '2026-07-01 10:00' )->day() );
	}

	/**
	 * An order placed just after midnight belongs to the new day, not the one
	 * that has just ended.
	 */
	public function test_an_order_just_after_midnight_belongs_to_the_new_day(): void {
		$this->assertSame( '2026-07-02', $this->order( '2026-07-02 00:30' )->day() );
	}

	/**
	 * The currency is the one the order's money is in.
	 */
	public function test_it_reports_its_currency(): void {
		$this->assertSame( 'EUR', $this->order()->currency() );
	}

	/**
	 * The coupons used come back as identifiers, since that is what everything
	 * downstream is keyed by.
	 */
	public function test_it_names_the_coupons_used(): void {
		$order = $this->order(
			discounts: array(
				7 => new Money( 500, 'EUR' ),
				9 => new Money( 250, 'EUR' ),
			)
		);

		$this->assertEquals( array( new CouponId( 7 ), new CouponId( 9 ) ), $order->coupons() );
	}

	/**
	 * An order nobody used a coupon on names none, rather than tripping over an
	 * empty list. Such orders exist in every shop and are read on every backfill.
	 */
	public function test_an_order_without_coupons_names_none(): void {
		$this->assertSame( array(), $this->order()->coupons() );
	}

	/**
	 * The identifiers are a list, not a map keyed by coupon ID. Callers iterate
	 * them, and preserved keys would make the result depend on the order the
	 * discounts happened to be stored in.
	 */
	public function test_the_coupons_come_back_as_a_list(): void {
		$order = $this->order(
			discounts: array(
				12 => new Money( 500, 'EUR' ),
				34 => new Money( 250, 'EUR' ),
			)
		);

		$this->assertSame( array( 0, 1 ), array_keys( $order->coupons() ) );
	}
}
