<?php
/**
 * Scope index unit tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Domain\Overlap;

use DFX\CouponAAW\Domain\Coupon\CouponScope;
use DFX\CouponAAW\Domain\Coupon\CouponSnapshot;
use DFX\CouponAAW\Domain\Overlap\ScopeIndex;
use DFX\CouponAAW\Tests\Fixtures\CouponSnapshotBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Narrows the pairs worth comparing, which §8.3 requires before comparison on
 * large inventories.
 *
 * Its contract is one-sided on purpose: it may offer a pair that turns out not
 * to intersect, but it must never withhold one that does. Cheap false
 * positives, no false negatives.
 */
final class ScopeIndexTest extends TestCase {

	/**
	 * A coupon with a given scope.
	 *
	 * @param int              $id    Post ID.
	 * @param CouponScope|null $scope Scope, universal when omitted.
	 */
	private function coupon( int $id, ?CouponScope $scope = null ): CouponSnapshot {
		$builder = CouponSnapshotBuilder::make()->with_id( $id )->with_code( 'c' . $id );

		return ( null === $scope ? $builder : $builder->with_scope( $scope ) )->build();
	}

	/**
	 * Reduce the pairs to comparable strings.
	 *
	 * @param list<CouponSnapshot> $coupons The inventory.
	 *
	 * @return list<string>
	 */
	private function pairs( array $coupons ): array {
		$pairs = array_map(
			static fn ( array $pair ): string => $pair[0]->code . '+' . $pair[1]->code,
			( new ScopeIndex() )->candidate_pairs( $coupons )
		);

		sort( $pairs );

		return $pairs;
	}

	/**
	 * Nothing to compare in an empty or single-coupon store.
	 */
	public function test_it_offers_no_pairs_for_fewer_than_two_coupons(): void {
		$this->assertSame( array(), $this->pairs( array() ) );
		$this->assertSame( array(), $this->pairs( array( $this->coupon( 1 ) ) ) );
	}

	/**
	 * Coupons on disjoint products are never offered. This is the whole point:
	 * a thousand coupons each on their own product should cost nothing.
	 */
	public function test_it_skips_coupons_on_different_products(): void {
		$this->assertSame(
			array(),
			$this->pairs(
				array(
					$this->coupon( 1, new CouponScope( included_products: array( 10 ) ) ),
					$this->coupon( 2, new CouponScope( included_products: array( 20 ) ) ),
				)
			)
		);
	}

	/**
	 * Coupons sharing a product are offered.
	 */
	public function test_it_offers_coupons_sharing_a_product(): void {
		$this->assertSame(
			array( 'c1+c2' ),
			$this->pairs(
				array(
					$this->coupon( 1, new CouponScope( included_products: array( 10 ) ) ),
					$this->coupon( 2, new CouponScope( included_products: array( 10 ) ) ),
				)
			)
		);
	}

	/**
	 * Coupons sharing a category are offered; on different categories they are
	 * not.
	 */
	public function test_it_offers_coupons_sharing_a_category(): void {
		$this->assertSame(
			array( 'c1+c2' ),
			$this->pairs(
				array(
					$this->coupon( 1, new CouponScope( included_categories: array( 5 ) ) ),
					$this->coupon( 2, new CouponScope( included_categories: array( 5 ) ) ),
					$this->coupon( 3, new CouponScope( included_categories: array( 6 ) ) ),
				)
			)
		);
	}

	/**
	 * A coupon with no restrictions applies to the whole catalogue, so it has
	 * to be compared against every other coupon there is.
	 *
	 * The two restricted coupons are both product-scoped and disjoint, so the
	 * only pairs that can appear are the ones the unrestricted coupon creates.
	 */
	public function test_an_unrestricted_coupon_is_paired_with_everything(): void {
		$this->assertSame(
			array( 'c1+c2', 'c1+c3' ),
			$this->pairs(
				array(
					$this->coupon( 1 ),
					$this->coupon( 2, new CouponScope( included_products: array( 10 ) ) ),
					$this->coupon( 3, new CouponScope( included_products: array( 20 ) ) ),
				)
			)
		);
	}

	/**
	 * Two unrestricted coupons are paired with each other as well.
	 */
	public function test_unrestricted_coupons_are_paired_with_one_another(): void {
		$this->assertSame(
			array( 'c1+c2' ),
			$this->pairs( array( $this->coupon( 1 ), $this->coupon( 2 ) ) )
		);
	}

	/**
	 * A coupon restricted by product and one restricted by category cannot be
	 * separated without knowing the catalogue, so the pair is offered and the
	 * detector decides. Dropping it here is exactly the false negative this
	 * class must never produce.
	 */
	public function test_it_offers_product_against_category_restrictions(): void {
		$this->assertSame(
			array( 'c1+c2' ),
			$this->pairs(
				array(
					$this->coupon( 1, new CouponScope( included_products: array( 10 ) ) ),
					$this->coupon( 2, new CouponScope( included_categories: array( 5 ) ) ),
				)
			)
		);
	}

	/**
	 * A pair reachable by several routes is still offered once.
	 */
	public function test_it_offers_each_pair_only_once(): void {
		$scope = new CouponScope( included_products: array( 10, 11 ), included_categories: array( 5, 6 ) );

		$this->assertSame(
			array( 'c1+c2' ),
			$this->pairs( array( $this->coupon( 1, $scope ), $this->coupon( 2, $scope ) ) )
		);
	}

	/**
	 * Pairs are ordered consistently, so results do not depend on the order the
	 * repository happened to return.
	 */
	public function test_pairs_are_ordered_by_id(): void {
		$scope = new CouponScope( included_products: array( 10 ) );

		$this->assertSame(
			array( 'c3+c9' ),
			$this->pairs( array( $this->coupon( 9, $scope ), $this->coupon( 3, $scope ) ) )
		);
	}

	/**
	 * The measurement §16 asks for, expressed as a test rather than a timing.
	 *
	 * Five hundred coupons each on their own product is 124,750 pairs to compare
	 * naively. The index reduces that to none, and does so deterministically —
	 * which is worth more here than a benchmark that goes flaky on a loaded CI
	 * runner.
	 */
	public function test_it_collapses_a_large_disjoint_inventory_to_nothing(): void {
		$coupons = array();

		for ( $i = 1; $i <= 500; $i++ ) {
			$coupons[] = $this->coupon( $i, new CouponScope( included_products: array( 1000 + $i ) ) );
		}

		$this->assertSame( array(), ( new ScopeIndex() )->candidate_pairs( $coupons ) );
	}

	/**
	 * And the case that cannot be collapsed, stated plainly so the cost is not a
	 * surprise: every coupon applying to everything is inherently quadratic, and
	 * no index can change that.
	 */
	public function test_an_inventory_of_unrestricted_coupons_stays_quadratic(): void {
		$coupons = array();

		for ( $i = 1; $i <= 50; $i++ ) {
			$coupons[] = $this->coupon( $i );
		}

		$this->assertCount( 1225, ( new ScopeIndex() )->candidate_pairs( $coupons ) );
	}
}
