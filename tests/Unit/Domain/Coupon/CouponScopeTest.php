<?php
/**
 * Coupon scope unit tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Domain\Coupon;

use DFX\CouponAAW\Domain\Coupon\CouponScope;
use DFX\CouponAAW\Domain\Coupon\ProductRef;
use PHPUnit\Framework\TestCase;

/**
 * The real product set a coupon affects, resolved from its restrictions (§8.2).
 */
final class CouponScopeTest extends TestCase {

	/**
	 * A coupon with no restrictions applies to the whole catalogue.
	 */
	public function test_an_unrestricted_scope_is_universal(): void {
		$scope = CouponScope::universal();

		$this->assertTrue( $scope->is_universal() );
		$this->assertTrue( $scope->contains( new ProductRef( 1 ) ) );
	}

	/**
	 * Any restriction at all makes a scope non-universal.
	 */
	public function test_any_restriction_makes_a_scope_non_universal(): void {
		$this->assertFalse( ( new CouponScope( included_products: array( 1 ) ) )->is_universal() );
		$this->assertFalse( ( new CouponScope( excluded_products: array( 1 ) ) )->is_universal() );
		$this->assertFalse( ( new CouponScope( included_categories: array( 1 ) ) )->is_universal() );
		$this->assertFalse( ( new CouponScope( excluded_categories: array( 1 ) ) )->is_universal() );
		$this->assertFalse( ( new CouponScope( excludes_sale_items: true ) )->is_universal() );
	}

	/**
	 * An include list is a whitelist: everything outside it is out of scope.
	 */
	public function test_included_products_exclude_everything_else(): void {
		$scope = new CouponScope( included_products: array( 10, 20 ) );

		$this->assertTrue( $scope->contains( new ProductRef( 10 ) ) );
		$this->assertFalse( $scope->contains( new ProductRef( 30 ) ) );
	}

	/**
	 * A product is in scope if any of its categories is included.
	 */
	public function test_an_included_category_brings_its_products_into_scope(): void {
		$scope = new CouponScope( included_categories: array( 5 ) );

		$this->assertTrue( $scope->contains( new ProductRef( 10, array( 5, 9 ) ) ) );
		$this->assertFalse( $scope->contains( new ProductRef( 11, array( 9 ) ) ) );
	}

	/**
	 * Product and category includes are a union, matching WooCommerce.
	 */
	public function test_product_and_category_includes_are_a_union(): void {
		$scope = new CouponScope( included_products: array( 10 ), included_categories: array( 5 ) );

		$this->assertTrue( $scope->contains( new ProductRef( 10, array( 99 ) ) ) );
		$this->assertTrue( $scope->contains( new ProductRef( 11, array( 5 ) ) ) );
		$this->assertFalse( $scope->contains( new ProductRef( 12, array( 99 ) ) ) );
	}

	/**
	 * Exclusion beats inclusion, however the product got in.
	 */
	public function test_an_excluded_product_is_out_even_when_included(): void {
		$scope = new CouponScope( included_products: array( 10 ), excluded_products: array( 10 ) );

		$this->assertFalse( $scope->contains( new ProductRef( 10 ) ) );
	}

	/**
	 * An excluded category removes its products even if a category include or
	 * the universal default would have let them in.
	 */
	public function test_an_excluded_category_removes_its_products(): void {
		$scope = new CouponScope( excluded_categories: array( 7 ) );

		$this->assertFalse( $scope->contains( new ProductRef( 10, array( 7 ) ) ) );
		$this->assertTrue( $scope->contains( new ProductRef( 11, array( 8 ) ) ) );
	}

	/**
	 * The exclude-sale-items flag is a scope restriction like any other.
	 */
	public function test_sale_items_are_excluded_when_the_flag_is_set(): void {
		$scope = new CouponScope( excludes_sale_items: true );

		$this->assertFalse( $scope->contains( new ProductRef( 10, array(), true ) ) );
		$this->assertTrue( $scope->contains( new ProductRef( 11, array(), false ) ) );
	}

	/**
	 * Duplicate and unsorted restriction lists are normalised, so two scopes
	 * expressing the same thing compare equal.
	 */
	public function test_it_normalises_its_restriction_lists(): void {
		$one = new CouponScope( included_products: array( 20, 10, 20 ) );
		$two = new CouponScope( included_products: array( 10, 20 ) );

		$this->assertSame( array( 10, 20 ), $one->included_products );
		$this->assertTrue( $one->equals( $two ) );
	}

	/**
	 * Scopes restricting different things are not equal.
	 */
	public function test_scopes_with_different_restrictions_are_not_equal(): void {
		$this->assertFalse(
			( new CouponScope( included_products: array( 10 ) ) )
				->equals( new CouponScope( included_categories: array( 10 ) ) )
		);
	}

	/**
	 * A universal scope overlaps everything. §8.2 calls this out as correct and
	 * as usually the most useful finding in the inventory.
	 */
	public function test_a_universal_scope_may_intersect_anything(): void {
		$scope = CouponScope::universal();

		$this->assertTrue( $scope->may_intersect( CouponScope::universal() ) );
		$this->assertTrue( $scope->may_intersect( new CouponScope( included_products: array( 10 ) ) ) );
		$this->assertTrue( ( new CouponScope( included_products: array( 10 ) ) )->may_intersect( $scope ) );
	}

	/**
	 * Two product whitelists sharing nothing cannot both apply to a product.
	 * This is the case the overlap detector most needs decided.
	 */
	public function test_disjoint_product_whitelists_do_not_intersect(): void {
		$this->assertFalse(
			( new CouponScope( included_products: array( 10, 20 ) ) )
				->may_intersect( new CouponScope( included_products: array( 30 ) ) )
		);
	}

	/**
	 * Overlapping product whitelists do intersect.
	 */
	public function test_overlapping_product_whitelists_intersect(): void {
		$this->assertTrue(
			( new CouponScope( included_products: array( 10, 20 ) ) )
				->may_intersect( new CouponScope( included_products: array( 20, 30 ) ) )
		);
	}

	/**
	 * A product shared on paper but excluded by one side is not shared at all.
	 */
	public function test_a_shared_product_excluded_by_either_side_does_not_intersect(): void {
		$this->assertFalse(
			( new CouponScope( included_products: array( 10 ) ) )
				->may_intersect( new CouponScope( included_products: array( 10 ), excluded_products: array( 10 ) ) )
		);
	}

	/**
	 * The same reasoning applies to category whitelists.
	 */
	public function test_disjoint_category_whitelists_do_not_intersect(): void {
		$this->assertFalse(
			( new CouponScope( included_categories: array( 5 ) ) )
				->may_intersect( new CouponScope( included_categories: array( 6 ) ) )
		);
	}

	/**
	 * Overlapping category whitelists intersect.
	 */
	public function test_overlapping_category_whitelists_intersect(): void {
		$this->assertTrue(
			( new CouponScope( included_categories: array( 5, 6 ) ) )
				->may_intersect( new CouponScope( included_categories: array( 6 ) ) )
		);
	}

	/**
	 * One scope restricted by product and the other by category cannot be
	 * settled without knowing which categories the product belongs to. The
	 * method is deliberately named `may_intersect`: false means provably
	 * disjoint, true means possibly overlapping. Milestone 5's indexed detector
	 * refines this case against the real catalogue.
	 */
	public function test_a_product_against_a_category_restriction_is_undecidable_here(): void {
		$this->assertTrue(
			( new CouponScope( included_products: array( 10 ) ) )
				->may_intersect( new CouponScope( included_categories: array( 5 ) ) )
		);
	}

	/**
	 * Excluding sale items narrows a scope but never empties it, so it cannot
	 * on its own make two scopes disjoint.
	 */
	public function test_excluding_sale_items_does_not_make_scopes_disjoint(): void {
		$this->assertTrue(
			( new CouponScope( included_products: array( 10 ), excludes_sale_items: true ) )
				->may_intersect( new CouponScope( included_products: array( 10 ) ) )
		);
	}

	/**
	 * Intersection is symmetric, whichever side asks.
	 */
	public function test_intersection_is_symmetric(): void {
		$one = new CouponScope( included_products: array( 10 ), excluded_products: array( 20 ) );
		$two = new CouponScope( included_products: array( 20, 10 ) );

		$this->assertSame( $one->may_intersect( $two ), $two->may_intersect( $one ) );
	}
}
