<?php
/**
 * A product, as a coupon overview needs to describe it.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Catalog;

use DFX\CouponAAW\Domain\Profit\Money;

/**
 * Enough about a product to name it on screen and say whether it can actually
 * be bought.
 *
 * A coupon restricted to a product nobody can buy is dead weight, and saying so
 * is worth as much as naming the product.
 */
final class ProductDetail {

	/**
	 * Constructor.
	 *
	 * @param int         $id           The product ID.
	 * @param string      $name         Its title.
	 * @param Money|null  $price        Its price, or null where it has none.
	 * @param bool        $is_available Whether a customer could buy it today.
	 * @param string|null $unavailable  Why not, when they could not.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly ?Money $price,
		public readonly bool $is_available,
		public readonly ?string $unavailable = null
	) {}
}
