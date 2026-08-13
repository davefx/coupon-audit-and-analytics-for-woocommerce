<?php
/**
 * Reading the filter out of the request.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Admin;

use DFX\CouponAAW\Domain\Coupon\CouponFilter;

/**
 * Turns what the URL says into a filter the domain understands.
 *
 * A screen reads input and formats output; it decides nothing (§5). So this
 * translates and validates, and `CouponFilter` does the judging.
 *
 * Anything it does not recognise is dropped rather than obeyed. A hand-edited
 * URL naming a discount type nobody offers would otherwise empty the table, and
 * a shop reading an empty coupon audit has every reason to think something has
 * gone badly wrong.
 */
final class InventoryFilterRequest {

	/**
	 * The query argument naming a discount type.
	 */
	public const TYPE_ARG = 'dfxcaaw_type';

	/**
	 * The query argument naming an expiry condition.
	 */
	public const EXPIRY_ARG = 'dfxcaaw_expiry';

	/**
	 * Coupons that expire.
	 */
	public const EXPIRY_WITH = 'with';

	/**
	 * Coupons that never do.
	 */
	public const EXPIRY_WITHOUT = 'without';

	/**
	 * The filter the current request asks for.
	 *
	 * @param array<string, mixed> $query The request, normally `$_GET`.
	 */
	public static function from( array $query ): CouponFilter {
		return new CouponFilter( self::discount_type( $query ), self::has_expiry( $query ) );
	}

	/**
	 * The discount types this store actually offers.
	 *
	 * Asked of WooCommerce rather than listed here: the types are filterable, so
	 * a store may have more than core ships with, and a hard-coded list would
	 * both miss those and keep offering any that core drops.
	 *
	 * @return array<string, string> Slug to label.
	 */
	public static function discount_types(): array {
		$types = function_exists( 'wc_get_coupon_types' ) ? wc_get_coupon_types() : array();

		return is_array( $types ) ? $types : array();
	}

	/**
	 * The discount type asked for, if it is one this store offers.
	 *
	 * @param array<string, mixed> $query The request.
	 */
	private static function discount_type( array $query ): ?string {
		$type = isset( $query[ self::TYPE_ARG ] ) ? sanitize_key( wp_unslash( $query[ self::TYPE_ARG ] ) ) : '';

		return array_key_exists( $type, self::discount_types() ) ? $type : null;
	}

	/**
	 * Whether the request asks for coupons that expire, that do not, or either.
	 *
	 * @param array<string, mixed> $query The request.
	 */
	private static function has_expiry( array $query ): ?bool {
		$expiry = isset( $query[ self::EXPIRY_ARG ] ) ? sanitize_key( wp_unslash( $query[ self::EXPIRY_ARG ] ) ) : '';

		return match ( $expiry ) {
			self::EXPIRY_WITH => true,
			self::EXPIRY_WITHOUT => false,
			default => null,
		};
	}
}
