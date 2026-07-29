<?php
/**
 * Catalogue lookups.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Catalog;

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
	 * The price of each of the given products.
	 *
	 * Asked for every product any coupon names, all at once. Asking per coupon
	 * turns a page load into one query per coupon, which at a few hundred
	 * coupons is the difference between a screen and a timeout.
	 *
	 * @param list<int> $ids Product IDs.
	 *
	 * @return array<int, Money> Keyed by ID; products with no price are absent.
	 */
	public function prices( array $ids ): array;

	/**
	 * The cheapest product in each of the given categories.
	 *
	 * @param list<int> $ids Category term IDs.
	 *
	 * @return array<int, Money> Keyed by term ID; empty categories are absent.
	 */
	public function cheapest_per_category( array $ids ): array;

	/**
	 * The cheapest product in the shop, which is what an unrestricted coupon
	 * reaches.
	 *
	 * Null where the answer is not knowable — a shop with nothing priced. Null
	 * means "not known" and never "nothing is cheap".
	 */
	public function cheapest_overall(): ?Money;
}
