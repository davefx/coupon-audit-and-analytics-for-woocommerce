<?php
/**
 * Coupon identifier.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Coupon;

use InvalidArgumentException;

/**
 * The WordPress post ID of a `shop_coupon`, with a type attached.
 *
 * The type is the point: a method taking a CouponId cannot be handed a product
 * ID, an order ID or a term ID by mistake, and those are all plain integers.
 */
final class CouponId {

	/**
	 * Constructor.
	 *
	 * @param int $value The post ID.
	 *
	 * @throws InvalidArgumentException When the value is not a possible post ID.
	 */
	public function __construct( public readonly int $value ) {
		if ( $value < 1 ) {
			throw new InvalidArgumentException(
				sprintf( 'A coupon ID must be a positive integer, got %d.', $value )
			);
		}
	}

	/**
	 * Whether two identifiers point at the same coupon.
	 *
	 * @param self $other The identifier to compare against.
	 */
	public function equals( self $other ): bool {
		return $this->value === $other->value;
	}
}
