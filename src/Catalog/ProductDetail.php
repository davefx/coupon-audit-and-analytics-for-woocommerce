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
     * @var int
     * @readonly
     */
    public int $id;
    /**
     * @var string
     * @readonly
     */
    public string $name;
    /**
     * @var Money|null
     * @readonly
     */
    public ?Money $price;
    /**
     * @var bool
     * @readonly
     */
    public bool $is_available;
    /**
     * @var string|null
     * @readonly
     */
    public ?string $unavailable = null;
    /**
	 * Constructor.
	 *
	 * @param int         $id           The product ID.
	 * @param string      $name         Its title.
	 * @param Money|null  $price        Its price, or null where it has none.
	 * @param bool        $is_available Whether a customer could buy it today.
	 * @param string|null $unavailable  Why not, when they could not.
	 */
	public function __construct(int $id, string $name, ?Money $price, bool $is_available, ?string $unavailable = null)
    {
        $this->id = $id;
        $this->name = $name;
        $this->price = $price;
        $this->is_available = $is_available;
        $this->unavailable = $unavailable;
    }
}
