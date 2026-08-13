<?php
/**
 * Coupon filter tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Domain\Coupon;

use DFX\CouponAAW\Domain\Coupon\CouponFilter;
use DFX\CouponAAW\Domain\Coupon\CouponSnapshot;
use DFX\CouponAAW\Domain\Coupon\CouponTerms;
use DFX\CouponAAW\Domain\Coupon\DiscountAmount;
use DFX\CouponAAW\Domain\Profit\Money;
use DFX\CouponAAW\Tests\Fixtures\CouponSnapshotBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Narrowing a large inventory to the part somebody is asking about.
 *
 * A shop with four hundred coupons cannot be read; the screen is only useful if
 * it can be asked a question. The two questions worth asking are "which of these
 * are percentages" — because a fixed discount fails differently — and "which of
 * these never expire", which is the finding most likely to be acted on.
 *
 * Nothing here decides what is *wrong* with a coupon. It decides what is being
 * looked at, which is why an unset filter matches everything rather than nothing.
 */
final class CouponFilterTest extends TestCase {

	/**
	 * A coupon of a given discount type, expiring or not.
	 *
	 * @param string      $type    WooCommerce's discount type slug.
	 * @param string|null $expires When it expires, or null for never.
	 */
	private function coupon( string $type = 'percent', ?string $expires = null ): CouponSnapshot {
		$amount = 'percent' === $type
			? DiscountAmount::percentage( 10.0, $type )
			: DiscountAmount::fixed( new Money( 500, 'EUR' ), $type );

		$builder = CouponSnapshotBuilder::make()->with_terms( new CouponTerms( $amount ) );

		if ( null !== $expires ) {
			$builder = $builder->expiring( $expires );
		}

		return $builder->build();
	}

	/**
	 * A filter nobody set matches everything, so an unfiltered screen shows the
	 * whole store rather than an empty table.
	 */
	public function test_an_empty_filter_matches_everything(): void {
		$filter = new CouponFilter();

		$this->assertTrue( $filter->is_empty() );
		$this->assertTrue( $filter->matches( $this->coupon( 'percent' ) ) );
		$this->assertTrue( $filter->matches( $this->coupon( 'fixed_cart', '2027-01-01' ) ) );
	}

	/**
	 * A discount type is matched on WooCommerce's own slug, which is what the
	 * coupon carries and what the dropdown offers.
	 */
	public function test_it_matches_a_discount_type(): void {
		$filter = new CouponFilter( discount_type: 'fixed_cart' );

		$this->assertFalse( $filter->is_empty() );
		$this->assertTrue( $filter->matches( $this->coupon( 'fixed_cart' ) ) );
		$this->assertFalse( $filter->matches( $this->coupon( 'percent' ) ) );
	}

	/**
	 * The two fixed types are told apart. A discount off the basket and a
	 * discount off each product are different things, and a shop looking for one
	 * is not looking for the other.
	 */
	public function test_the_two_fixed_types_are_not_the_same(): void {
		$filter = new CouponFilter( discount_type: 'fixed_product' );

		$this->assertTrue( $filter->matches( $this->coupon( 'fixed_product' ) ) );
		$this->assertFalse( $filter->matches( $this->coupon( 'fixed_cart' ) ) );
	}

	/**
	 * Coupons that expire, and coupons that do not.
	 */
	public function test_it_matches_on_whether_a_coupon_expires(): void {
		$expiring = new CouponFilter( has_expiry: true );
		$forever  = new CouponFilter( has_expiry: false );

		$this->assertTrue( $expiring->matches( $this->coupon( 'percent', '2027-01-01' ) ) );
		$this->assertFalse( $expiring->matches( $this->coupon( 'percent' ) ) );

		$this->assertTrue( $forever->matches( $this->coupon( 'percent' ) ) );
		$this->assertFalse( $forever->matches( $this->coupon( 'percent', '2027-01-01' ) ) );
	}

	/**
	 * Asking two things at once narrows to coupons answering both, not either.
	 */
	public function test_two_conditions_both_have_to_hold(): void {
		$filter = new CouponFilter( discount_type: 'fixed_cart', has_expiry: false );

		$this->assertTrue( $filter->matches( $this->coupon( 'fixed_cart' ) ) );
		$this->assertFalse( $filter->matches( $this->coupon( 'fixed_cart', '2027-01-01' ) ) );
		$this->assertFalse( $filter->matches( $this->coupon( 'percent' ) ) );
	}

	/**
	 * An expiry filter says nothing about the discount type, and the other way
	 * round — so each stays answerable on its own.
	 */
	public function test_each_condition_ignores_the_other(): void {
		$this->assertTrue(
			( new CouponFilter( has_expiry: true ) )->matches( $this->coupon( 'fixed_product', '2027-01-01' ) )
		);

		$this->assertTrue(
			( new CouponFilter( discount_type: 'percent' ) )->matches( $this->coupon( 'percent', '2027-01-01' ) )
		);
	}
}
