<?php
/**
 * Catalogue lookups.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Catalog;

use DFX\CouponAAW\Domain\Coupon\CouponScope;
use DFX\CouponAAW\Domain\Profit\Money;

/**
 * What the audit needs to know about the shop's products and categories.
 *
 * Behind an interface like everything else that touches the database, so the
 * screen and the findings can both be tested without one.
 */
interface CatalogRepositoryInterface {

	/**
	 * Describe the given products.
	 *
	 * @param list<int> $ids Product IDs.
	 *
	 * @return array<int, ProductDetail> Keyed by ID; missing products are absent.
	 */
	public function products( array $ids ): array;

	/**
	 * Name the given product categories.
	 *
	 * @param list<int> $ids Category term IDs.
	 *
	 * @return array<int, string> Keyed by term ID; missing categories are absent.
	 */
	public function category_names( array $ids ): array;

	/**
	 * The lowest price a coupon with this scope could be applied to.
	 *
	 * Null where the answer is not knowable — a shop with no priced products, or
	 * one whose lookup table has not been built. Null means "not known" and never
	 * "nothing is cheap".
	 *
	 * @param CouponScope $scope The coupon's scope.
	 */
	public function cheapest_in_scope( CouponScope $scope ): ?Money;
}
