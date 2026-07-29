<?php
/**
 * Coupon configuration audit unit tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Domain\Coupon;

use DFX\CouponAAW\Domain\Coupon\ConfigurationAuditor;
use DFX\CouponAAW\Domain\Coupon\ConfigurationIssue;
use DFX\CouponAAW\Domain\Coupon\CouponScope;
use DFX\CouponAAW\Domain\Coupon\CouponSnapshot;
use DFX\CouponAAW\Domain\Coupon\CouponTerms;
use DFX\CouponAAW\Domain\Coupon\DiscountAmount;
use DFX\CouponAAW\Domain\Profit\Money;
use DFX\CouponAAW\Tests\Fixtures\CouponSnapshotBuilder;
use PHPUnit\Framework\TestCase;

/**
 * A coupon that gives away more than it asks for.
 *
 * WooCommerce will apply a £10 fixed discount to a £6 basket without comment,
 * and nothing in it warns the shop first. This is the same family of finding as
 * an orphan or an overlap — something wrong that only a tool looking for it will
 * ever say out loud.
 */
final class ConfigurationAuditorTest extends TestCase {

	/**
	 * Build a coupon with given terms.
	 *
	 * @param CouponTerms      $terms The commercial terms.
	 * @param CouponScope|null $scope The scope, universal when omitted.
	 */
	private function coupon( CouponTerms $terms, ?CouponScope $scope = null ): CouponSnapshot {
		$builder = CouponSnapshotBuilder::make()->with_terms( $terms );

		return ( null === $scope ? $builder : $builder->with_scope( $scope ) )->build();
	}

	/**
	 * A fixed discount of a given size, with an optional minimum spend.
	 *
	 * @param int      $discount Discount in minor units.
	 * @param int|null $minimum  Minimum spend in minor units, if any.
	 */
	private function fixed( int $discount, ?int $minimum = null ): CouponTerms {
		return new CouponTerms(
			DiscountAmount::fixed( new Money( $discount, 'EUR' ) ),
			null === $minimum ? null : new Money( $minimum, 'EUR' )
		);
	}

	/**
	 * A percentage discount cannot exceed what it is applied to, whatever it is
	 * set to, so it is never reported.
	 */
	public function test_a_percentage_discount_is_never_at_fault(): void {
		$coupon = $this->coupon(
			new CouponTerms( DiscountAmount::percentage( 90.0 ), new Money( 100, 'EUR' ) )
		);

		$this->assertSame( array(), ( new ConfigurationAuditor() )->issues( $coupon, new Money( 50, 'EUR' ) ) );
	}

	/**
	 * A fixed discount worth more than the basket it demands hands money over.
	 */
	public function test_a_discount_larger_than_the_minimum_spend_is_reported(): void {
		$coupon = $this->coupon( $this->fixed( 1000, 500 ) );

		$this->assertSame(
			array( ConfigurationIssue::DISCOUNT_EXCEEDS_MINIMUM_SPEND ),
			( new ConfigurationAuditor() )->issues( $coupon )
		);
	}

	/**
	 * A minimum spend above the discount is exactly what it should be.
	 */
	public function test_a_sound_minimum_spend_is_not_reported(): void {
		$coupon = $this->coupon( $this->fixed( 1000, 5000 ) );

		$this->assertSame( array(), ( new ConfigurationAuditor() )->issues( $coupon ) );
	}

	/**
	 * Equal is fine: a £10 coupon on a £10 minimum takes the basket to nothing
	 * but never below it.
	 */
	public function test_a_discount_equal_to_the_minimum_spend_is_not_reported(): void {
		$coupon = $this->coupon( $this->fixed( 1000, 1000 ) );

		$this->assertSame( array(), ( new ConfigurationAuditor() )->issues( $coupon ) );
	}

	/**
	 * A fixed discount worth more than something it applies to is a way of
	 * paying customers to take it.
	 */
	public function test_a_discount_larger_than_a_reachable_product_is_reported(): void {
		$coupon = $this->coupon(
			$this->fixed( 1000, 5000 ),
			new CouponScope( included_products: array( 10 ) )
		);

		$this->assertSame(
			array( ConfigurationIssue::DISCOUNT_EXCEEDS_PRODUCT_PRICE ),
			( new ConfigurationAuditor() )->issues( $coupon, new Money( 600, 'EUR' ) )
		);
	}

	/**
	 * Both faults at once are both reported, in a stable order.
	 */
	public function test_both_faults_are_reported_together(): void {
		$coupon = $this->coupon(
			$this->fixed( 1000, 500 ),
			new CouponScope( included_products: array( 10 ) )
		);

		$this->assertSame(
			array(
				ConfigurationIssue::DISCOUNT_EXCEEDS_MINIMUM_SPEND,
				ConfigurationIssue::DISCOUNT_EXCEEDS_PRODUCT_PRICE,
			),
			( new ConfigurationAuditor() )->issues( $coupon, new Money( 600, 'EUR' ) )
		);
	}

	/**
	 * A fixed discount with no minimum and no scope is flagged as unbounded.
	 * It is not certainly wrong, but it is one cheap product away from being so.
	 */
	public function test_a_fixed_discount_with_no_bound_at_all_is_reported(): void {
		$coupon = $this->coupon( $this->fixed( 1000 ) );

		$this->assertSame(
			array( ConfigurationIssue::UNBOUNDED_FIXED_DISCOUNT ),
			( new ConfigurationAuditor() )->issues( $coupon )
		);
	}

	/**
	 * The unbounded warning gives way to the definite faults rather than piling
	 * on top of them: a coupon already reported for a concrete reason does not
	 * also need telling that it might have one.
	 */
	public function test_the_unbounded_warning_yields_to_a_definite_fault(): void {
		$coupon = $this->coupon( $this->fixed( 1000, 500 ) );

		$this->assertNotContains(
			ConfigurationIssue::UNBOUNDED_FIXED_DISCOUNT,
			( new ConfigurationAuditor() )->issues( $coupon )
		);
	}

	/**
	 * A coupon whose reachable price is unknown is judged on its terms alone.
	 * Not knowing the catalogue is not evidence of a fault.
	 */
	public function test_an_unknown_price_produces_no_finding_of_its_own(): void {
		$coupon = $this->coupon(
			$this->fixed( 1000, 5000 ),
			new CouponScope( included_products: array( 10 ) )
		);

		$this->assertSame( array(), ( new ConfigurationAuditor() )->issues( $coupon, null ) );
	}

	/**
	 * Amounts in different currencies are not compared. §8.5 keeps currencies
	 * apart, and a shop whose coupon and catalogue disagree is telling us
	 * something we cannot resolve rather than something to guess at.
	 */
	public function test_amounts_in_different_currencies_are_not_compared(): void {
		$coupon = $this->coupon(
			$this->fixed( 1000, 5000 ),
			new CouponScope( included_products: array( 10 ) )
		);

		$this->assertSame(
			array(),
			( new ConfigurationAuditor() )->issues( $coupon, new Money( 600, 'USD' ) )
		);
	}

	/**
	 * A discount of nothing is not a fault, however it is bounded.
	 */
	public function test_a_zero_discount_is_not_reported(): void {
		$coupon = $this->coupon( $this->fixed( 0 ) );

		$this->assertSame( array(), ( new ConfigurationAuditor() )->issues( $coupon ) );
	}
}
