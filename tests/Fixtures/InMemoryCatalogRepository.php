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
use DFX\CouponAAW\Domain\Coupon\CouponScope;
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
	 * @param Money|null                $cheapest  What every scope query answers.
	 */
	public function __construct(
		private readonly array $catalogue = array(),
		private readonly array $names = array(),
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
	 * The cheapest reachable price.
	 *
	 * @param CouponScope $scope The coupon's scope.
	 */
	public function cheapest_in_scope( CouponScope $scope ): ?Money {
		return $this->cheapest;
	}
}
