<?php
/**
 * Candidate pair generation for overlap detection.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Overlap;

use DFX\CouponAAW\Domain\Coupon\CouponSnapshot;

/**
 * Narrows the pairs of coupons worth comparing.
 *
 * Comparing every coupon against every other is quadratic, which §8.3 says must
 * be indexed by product and category before comparison. This is that index.
 *
 * Its contract is deliberately one-sided: it may offer a pair that turns out not
 * to intersect, but it must never withhold one that does. A false positive costs
 * one cheap scope comparison; a false negative produces a clean report that is
 * wrong, which is the worst thing a tool like this can do.
 *
 * Four routes lead to a pair being offered:
 *
 * - both coupons name the same product,
 * - both name the same category,
 * - one restricts nothing, so it covers the whole catalogue,
 * - one restricts by product while the other restricts by category, which
 *   cannot be separated without knowing which categories that product is in.
 *
 * The last route is why an inventory split evenly between product-scoped and
 * category-scoped coupons stays quadratic. So does one where most coupons
 * restrict nothing. Neither is a flaw in the index — those coupons genuinely do
 * all have to be compared.
 */
final class ScopeIndex {

	/**
	 * Every pair of coupons that could possibly intersect.
	 *
	 * @param list<CouponSnapshot> $coupons The inventory.
	 *
	 * @return list<array{0: CouponSnapshot, 1: CouponSnapshot}>
	 */
	public function candidate_pairs( array $coupons ): array {
		if ( count( $coupons ) < 2 ) {
			return array();
		}

		$by_id = array();

		foreach ( $coupons as $coupon ) {
			$by_id[ $coupon->id->value ] = $coupon;
		}

		$pairs = array();

		foreach ( $this->pair_keys( $coupons ) as $key ) {
			[ $one, $other ] = explode( ':', $key );

			$pairs[] = array( $by_id[ (int) $one ], $by_id[ (int) $other ] );
		}

		return $pairs;
	}

	/**
	 * The identifying keys of every candidate pair, deduplicated and ordered.
	 *
	 * @param list<CouponSnapshot> $coupons The inventory.
	 *
	 * @return list<string>
	 */
	private function pair_keys( array $coupons ): array {
		$unrestricted       = array();
		$restricted         = array();
		$product_scoped     = array();
		$category_scoped    = array();
		$sharing_a_product  = array();
		$sharing_a_category = array();

		foreach ( $coupons as $coupon ) {
			$id    = $coupon->id->value;
			$scope = $coupon->scope;

			if ( array() === $scope->included_products && array() === $scope->included_categories ) {
				$unrestricted[] = $id;
				continue;
			}

			$restricted[] = $id;

			if ( array() !== $scope->included_products ) {
				$product_scoped[] = $id;

				foreach ( $scope->included_products as $product_id ) {
					$sharing_a_product[ $product_id ][] = $id;
				}
			}

			if ( array() !== $scope->included_categories ) {
				$category_scoped[] = $id;

				foreach ( $scope->included_categories as $category_id ) {
					$sharing_a_category[ $category_id ][] = $id;
				}
			}
		}

		$keys = array();

		// A coupon that restricts nothing meets everything, including the other
		// coupons that restrict nothing.
		$this->add_cross( $keys, $unrestricted, $restricted );
		$this->add_within( $keys, $unrestricted );

		foreach ( $sharing_a_product as $group ) {
			$this->add_within( $keys, $group );
		}

		foreach ( $sharing_a_category as $group ) {
			$this->add_within( $keys, $group );
		}

		// Undecidable without the catalogue, so it is decided by comparing.
		$this->add_cross( $keys, $product_scoped, $category_scoped );

		$keys = array_keys( $keys );

		// Natural order, so "1:2" precedes "1:10" rather than following it.
		sort( $keys, SORT_NATURAL );

		return $keys;
	}

	/**
	 * Record every pair within one group.
	 *
	 * @param array<string, true> $keys  Accumulated pair keys, by reference.
	 * @param list<int>           $group Coupon IDs that share something.
	 */
	private function add_within( array &$keys, array $group ): void {
		$count = count( $group );

		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$this->add_pair( $keys, $group[ $i ], $group[ $j ] );
			}
		}
	}

	/**
	 * Record every pair across two groups.
	 *
	 * @param array<string, true> $keys Accumulated pair keys, by reference.
	 * @param list<int>           $left  Coupon IDs on one side.
	 * @param list<int>           $right Coupon IDs on the other.
	 */
	private function add_cross( array &$keys, array $left, array $right ): void {
		foreach ( $left as $one ) {
			foreach ( $right as $other ) {
				$this->add_pair( $keys, $one, $other );
			}
		}
	}

	/**
	 * Record one pair, ordered by ID so it is recognised however it is reached.
	 *
	 * @param array<string, true> $keys  Accumulated pair keys, by reference.
	 * @param int                 $one   A coupon ID.
	 * @param int                 $other Another coupon ID.
	 */
	private function add_pair( array &$keys, int $one, int $other ): void {
		if ( $one === $other ) {
			return;
		}

		$low  = min( $one, $other );
		$high = max( $one, $other );

		$keys[ $low . ':' . $high ] = true;
	}
}
