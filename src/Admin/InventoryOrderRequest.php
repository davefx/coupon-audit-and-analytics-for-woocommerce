<?php
/**
 * Reading the sort order out of the request.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Admin;

use DFX\CouponAAW\Service\InventoryOrder;

/**
 * Turns what the URL says into an order the service understands.
 *
 * A list table used to sort the rows it had been handed, which was fine while
 * it was handed all of them. It is now handed one page, so the order has to be
 * decided before the page is chosen — and that means here, next to the filter,
 * rather than in the table.
 *
 * An unrecognised column is not an error. `InventoryOrder` falls back to the
 * code, because this arrives from a query string and anybody can type one.
 */
final class InventoryOrderRequest {

	/**
	 * The order the current request asks for.
	 *
	 * @param array<string, mixed> $query The request, normally `$_GET`.
	 */
	public static function from( array $query ): InventoryOrder {
		$by = isset( $query['orderby'] ) ? sanitize_key( wp_unslash( $query['orderby'] ) ) : InventoryOrder::BY_CODE;

		$descending = isset( $query['order'] )
			&& 'desc' === strtolower( sanitize_key( wp_unslash( $query['order'] ) ) );

		return new InventoryOrder( $by, $descending );
	}

	/**
	 * Which page of results the request asks for, counting from one.
	 *
	 * @param array<string, mixed> $query The request, normally `$_GET`.
	 */
	public static function page( array $query ): int {
		$page = isset( $query['paged'] ) ? absint( $query['paged'] ) : 1;

		return max( 1, $page );
	}
}
