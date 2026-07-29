<?php
/**
 * Scope pricing tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Service;

use DFX\CouponAAW\Catalog\ProductDetail;
use DFX\CouponAAW\Domain\Coupon\CouponScope;
use DFX\CouponAAW\Domain\Coupon\CouponSnapshot;
use DFX\CouponAAW\Domain\Coupon\CouponTerms;
use DFX\CouponAAW\Domain\Coupon\DiscountAmount;
use DFX\CouponAAW\Domain\Profit\Money;
use DFX\CouponAAW\Service\ScopePricing;
use DFX\CouponAAW\Tests\Fixtures\CouponSnapshotBuilder;
use DFX\CouponAAW\Tests\Fixtures\InMemoryCatalogRepository;
use PHPUnit\Framework\TestCase;

/**
 * The prefetched answer has to match the one a per-coupon lookup would have
 * given, or the page got faster by getting wrong.
 */
final class ScopePricingTest extends TestCase {

	/**
	 * An amount in euro minor units.
	 *
	 * @param int $minor The amount.
	 */
	private function eur( int $minor ): Money {
		return new Money( $minor, 'EUR' );
	}

	/**
	 * A product priced at the given amount.
	 *
	 * @param int $id    Its ID.
	 * @param int $minor Its price in minor units.
	 */
	private function product( int $id, int $minor ): ProductDetail {
		return new ProductDetail( $id, 'Product ' . $id, $this->eur( $minor ), true, null );
	}

	/**
	 * A coupon with the given scope and discount.
	 *
	 * @param CouponScope $scope The scope.
	 * @param bool        $fixed Whether the discount is a fixed sum.
	 */
	private function coupon( CouponScope $scope, bool $fixed = true ): CouponSnapshot {
		return CouponSnapshotBuilder::make()
			->with_scope( $scope )
			->with_terms(
				new CouponTerms(
					$fixed
						? DiscountAmount::fixed( $this->eur( 1000 ) )
						: DiscountAmount::percentage( 10.0 )
				)
			)
			->build();
	}

	/**
	 * A coupon restricted to named products is priced from those products.
	 */
	public function test_a_product_restricted_coupon_takes_the_cheapest_named_product(): void {
		$catalog = new InMemoryCatalogRepository(
			array(
				7 => $this->product( 7, 8000 ),
				9 => $this->product( 9, 350 ),
			)
		);

		$scope   = new CouponScope( included_products: array( 7, 9 ) );
		$pricing = new ScopePricing( $catalog, array( $this->coupon( $scope ) ) );

		$this->assertSame( 350, $pricing->cheapest( $scope )?->amount );
	}

	/**
	 * An excluded product is not something the coupon reaches, however cheap.
	 */
	public function test_an_excluded_product_is_not_the_answer(): void {
		$catalog = new InMemoryCatalogRepository(
			array(
				7 => $this->product( 7, 8000 ),
				9 => $this->product( 9, 350 ),
			)
		);

		$scope   = new CouponScope( included_products: array( 7, 9 ), excluded_products: array( 9 ) );
		$pricing = new ScopePricing( $catalog, array( $this->coupon( $scope ) ) );

		$this->assertSame( 8000, $pricing->cheapest( $scope )?->amount );
	}

	/**
	 * A category-restricted coupon is priced from that category's cheapest.
	 */
	public function test_a_category_restricted_coupon_takes_the_cheapest_in_the_category(): void {
		$catalog = new InMemoryCatalogRepository(
			per_category: array( 4 => $this->eur( 1200 ) ),
			cheapest: $this->eur( 50 )
		);

		$scope   = new CouponScope( included_categories: array( 4 ) );
		$pricing = new ScopePricing( $catalog, array( $this->coupon( $scope ) ) );

		$this->assertSame(
			1200,
			$pricing->cheapest( $scope )?->amount,
			'The bargain outside the category is not something this coupon reaches.'
		);
	}

	/**
	 * An unrestricted coupon is priced from the whole shop.
	 */
	public function test_an_unrestricted_coupon_takes_the_cheapest_in_the_shop(): void {
		$catalog = new InMemoryCatalogRepository( cheapest: $this->eur( 50 ) );

		$scope   = CouponScope::universal();
		$pricing = new ScopePricing( $catalog, array( $this->coupon( $scope ) ) );

		$this->assertSame( 50, $pricing->cheapest( $scope )?->amount );
	}

	/**
	 * A category combined with product exclusions cannot be answered from
	 * prefetched figures: the excluded product might be the category's cheapest,
	 * and what would then be next is not knowable from what was fetched.
	 *
	 * Saying nothing is the point. The finding this feeds is advisory, and a
	 * confidently wrong price in it is worse than a missing one.
	 */
	public function test_a_category_with_exclusions_declines_to_answer(): void {
		$catalog = new InMemoryCatalogRepository(
			catalogue: array( 9 => $this->product( 9, 350 ) ),
			per_category: array( 4 => $this->eur( 1200 ) )
		);

		$scope   = new CouponScope( included_categories: array( 4 ), excluded_products: array( 9 ) );
		$pricing = new ScopePricing( $catalog, array( $this->coupon( $scope ) ) );

		$this->assertNull( $pricing->cheapest( $scope ) );
	}

	/**
	 * Products and categories together take whichever is cheaper, since the
	 * coupon reaches both.
	 */
	public function test_products_and_categories_are_weighed_against_each_other(): void {
		$catalog = new InMemoryCatalogRepository(
			catalogue: array( 7 => $this->product( 7, 8000 ) ),
			per_category: array( 4 => $this->eur( 1200 ) )
		);

		$scope   = new CouponScope( included_products: array( 7 ), included_categories: array( 4 ) );
		$pricing = new ScopePricing( $catalog, array( $this->coupon( $scope ) ) );

		$this->assertSame( 1200, $pricing->cheapest( $scope )?->amount );
	}

	/**
	 * A percentage coupon asks the catalogue nothing. A percentage cannot exceed
	 * what it is applied to, so there is no question to answer — and on a shop
	 * whose coupons are all percentages this is the difference between a few
	 * queries and none.
	 */
	public function test_percentage_coupons_are_not_priced_at_all(): void {
		$catalog = new InMemoryCatalogRepository( cheapest: $this->eur( 50 ) );

		$scope   = CouponScope::universal();
		$pricing = new ScopePricing( $catalog, array( $this->coupon( $scope, false ) ) );

		$this->assertNull( $pricing->cheapest( $scope ) );
	}

	/**
	 * A product the catalogue does not know is passed over rather than counted
	 * as free.
	 */
	public function test_an_unknown_product_is_not_free(): void {
		$scope   = new CouponScope( included_products: array( 999 ) );
		$pricing = new ScopePricing( new InMemoryCatalogRepository(), array( $this->coupon( $scope ) ) );

		$this->assertNull( $pricing->cheapest( $scope ) );
	}
}
