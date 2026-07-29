<?php
/**
 * In-memory catalogue.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Fixtures;

use DFX\CouponAAW\Catalog\CatalogRepositoryInterface;
use DFX\CouponAAW\Catalog\ProductDetail;
use DFX\CouponAAW\Domain\Profit\Money;

/**
 * Lets the screen and the findings be tested without a catalogue.
 */
final class InMemoryCatalogRepository implements CatalogRepositoryInterface {

	/**
	 * Constructor.
	 *
	 * @param array<int, ProductDetail> $catalogue Products, keyed by ID.
	 * @param array<int, string>        $names     Category names, keyed by term ID.
	 * @param array<int, Money>         $per_category The cheapest in each category.
	 * @param Money|null                $cheapest     The cheapest in the shop.
	 */
	public function __construct(
		private readonly array $catalogue = array(),
		private readonly array $names = array(),
		private readonly array $per_category = array(),
		private readonly ?Money $cheapest = null
	) {}

	/**
	 * Describe the given products.
	 *
	 * @param list<int> $ids Product IDs.
	 *
	 * @return array<int, ProductDetail>
	 */
	public function products( array $ids ): array {
		return array_intersect_key( $this->catalogue, array_flip( $ids ) );
	}

	/**
	 * Name the given categories.
	 *
	 * @param list<int> $ids Category term IDs.
	 *
	 * @return array<int, string>
	 */
	public function category_names( array $ids ): array {
		return array_intersect_key( $this->names, array_flip( $ids ) );
	}

	/**
	 * The price of each of the given products.
	 *
	 * @param list<int> $ids Product IDs.
	 *
	 * @return array<int, Money>
	 */
	public function prices( array $ids ): array {
		$prices = array();

		foreach ( array_intersect_key( $this->catalogue, array_flip( $ids ) ) as $id => $product ) {
			if ( null !== $product->price ) {
				$prices[ $id ] = $product->price;
			}
		}

		return $prices;
	}

	/**
	 * The cheapest product in each of the given categories.
	 *
	 * @param list<int> $ids Category term IDs.
	 *
	 * @return array<int, Money>
	 */
	public function cheapest_per_category( array $ids ): array {
		return array_intersect_key( $this->per_category, array_flip( $ids ) );
	}

	/**
	 * The cheapest product in the shop.
	 */
	public function cheapest_overall(): ?Money {
		return $this->cheapest;
	}
}
