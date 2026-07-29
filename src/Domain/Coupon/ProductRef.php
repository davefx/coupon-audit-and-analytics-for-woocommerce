<?php
/**
 * Product reference.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Coupon;

use InvalidArgumentException;

/**
 * The minimum a scope needs to know about a product to decide whether a coupon
 * applies to it.
 *
 * Deliberately not a WooCommerce product: the domain never loads one. A
 * repository projects whatever it has into this shape.
 */
final class ProductRef {

	/**
	 * Category term IDs, deduplicated and sorted.
	 *
	 * @var list<int>
	 */
	public readonly array $category_ids;

	/**
	 * Constructor.
	 *
	 * @param int       $id           The product post ID.
	 * @param list<int> $category_ids Category term IDs the product belongs to.
	 * @param bool      $is_on_sale   Whether the product is currently on sale.
	 *
	 * @throws InvalidArgumentException When the ID is not a possible post ID.
	 */
	public function __construct(
		public readonly int $id,
		array $category_ids = array(),
		public readonly bool $is_on_sale = false
	) {
		if ( $id < 1 ) {
			throw new InvalidArgumentException(
				sprintf( 'A product ID must be a positive integer, got %d.', $id )
			);
		}

		$unique = array_values( array_unique( $category_ids ) );
		sort( $unique );

		$this->category_ids = $unique;
	}
}
