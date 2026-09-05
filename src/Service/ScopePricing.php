<?php
/**
 * The cheapest thing each coupon can reach.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Service;

use DFX\CouponAAW\Catalog\CatalogRepositoryInterface;
use DFX\CouponAAW\Domain\Coupon\CouponScope;
use DFX\CouponAAW\Domain\Coupon\CouponSnapshot;
use DFX\CouponAAW\Domain\Profit\Money;

/**
 * Answers "what is the cheapest thing this coupon applies to" for a whole
 * inventory, from a fixed number of queries.
 *
 * Asking the catalogue per coupon is the obvious implementation and it does not
 * survive contact with a real shop: five hundred coupons meant five hundred
 * queries and several seconds. Everything any coupon refers to is fetched once
 * up front instead, and each coupon's answer is then arithmetic.
 *
 * Where an answer cannot be reached exactly it is not given at all. A coupon
 * that excludes products *and* restricts by category is the awkward case: the
 * cheapest thing in that category might be one of the excluded products, and
 * from the prefetched figures there is no way to tell what the next cheapest
 * would be. Rather than report a price that might be wrong, that category is
 * passed over — the finding it feeds is advisory, and a wrong number in it
 * would cost more than a missing one.
 */
final class ScopePricing {

	/**
     * Price of every product any coupon names, keyed by ID.
     *
     * @var array<int, Money>
     * @readonly
     */
    private array $product_prices;

	/**
     * Cheapest product in each category any coupon names, keyed by term ID.
     *
     * @var array<int, Money>
     * @readonly
     */
    private array $category_prices;

	/**
     * Cheapest product in the shop, or null if nothing is priced.
     *
     * @var Money|null
     * @readonly
     */
    private ?Money $cheapest_overall;

	/**
	 * Fetch everything the given coupons could possibly need.
	 *
	 * Built for one set of coupons and never mutated afterwards. It is deliberately
	 * not a shared service: a prefetched price is only true of the shop as it was
	 * when it was read, and a cache of those that outlived the request would answer
	 * later questions with earlier facts.
	 *
	 * Only fixed-sum discounts are considered. A percentage cannot exceed what
	 * it is applied to, so for the commonest kind of coupon there is nothing the
	 * catalogue could say that would change anything, and a shop whose coupons
	 * are all percentages pays nothing at all for this.
	 *
	 * @param CatalogRepositoryInterface $catalog Where the figures come from.
	 * @param list<CouponSnapshot>       $coupons The inventory.
	 */
	public function __construct( CatalogRepositoryInterface $catalog, array $coupons ) {
		$products   = array();
		$categories = array();
		$universal  = false;

		foreach ( $coupons as $coupon ) {
			if ( ! $coupon->terms->amount->is_fixed() ) {
				continue;
			}

			$scope      = $coupon->scope;
			$products   = array_merge( $products, $scope->included_products, $scope->excluded_products );
			$categories = array_merge( $categories, $scope->included_categories );

			if ( array() === $scope->included_products && array() === $scope->included_categories ) {
				$universal = true;
			}
		}

		$this->product_prices   = $catalog->prices( array_values( array_unique( $products ) ) );
		$this->category_prices  = $catalog->cheapest_per_category( array_values( array_unique( $categories ) ) );
		$this->cheapest_overall = $universal ? $catalog->cheapest_overall() : null;
	}

	/**
	 * The cheapest thing a coupon with this scope can be applied to.
	 *
	 * @param CouponScope $scope The coupon's scope.
	 */
	public function cheapest( CouponScope $scope ): ?Money {
		$candidates = array();

		foreach ( $scope->included_products as $id ) {
			// An excluded product is not something the coupon reaches, however
			// cheap it is.
			if ( in_array( $id, $scope->excluded_products, true ) ) {
				continue;
			}

			if ( isset( $this->product_prices[ $id ] ) ) {
				$candidates[] = $this->product_prices[ $id ];
			}
		}

		foreach ( $scope->included_categories as $id ) {
			// See the note on this class: a category combined with product
			// exclusions cannot be answered exactly from prefetched figures.
			if ( array() !== $scope->excluded_products ) {
				continue;
			}

			if ( isset( $this->category_prices[ $id ] ) ) {
				$candidates[] = $this->category_prices[ $id ];
			}
		}

		if ( array() === $scope->included_products && array() === $scope->included_categories ) {
			if ( null !== $this->cheapest_overall && array() === $scope->excluded_products ) {
				$candidates[] = $this->cheapest_overall;
			}
		}

		return $this->lowest( $candidates );
	}

	/**
	 * The smallest of the given amounts, ignoring any in another currency.
	 *
	 * @param list<Money> $amounts The candidates.
	 */
	private function lowest( array $amounts ): ?Money {
		$lowest = null;

		foreach ( $amounts as $amount ) {
			if ( null === $lowest ) {
				$lowest = $amount;

				continue;
			}

			if ( $amount->currency === $lowest->currency && $amount->amount < $lowest->amount ) {
				$lowest = $amount;
			}
		}

		return $lowest;
	}
}
