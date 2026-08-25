<?php
/**
 * How the inventory screen is sorted.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Service;

/**
 * Which column the table is ordered by, and which way.
 *
 * Sorting used to happen in the list table, on the rows it had been handed.
 * That only works while it is handed all of them: a table given twenty rows out
 * of twenty-six thousand and asked to sort them would order the page rather
 * than the shop, and page two would follow page one only by accident. So the
 * order became part of the question the screen asks, and this holds it.
 *
 * An unrecognised column falls back to the code rather than raising. It arrives
 * from a query string, which anybody can type.
 */
final class InventoryOrder {

	/**
	 * Order by the coupon's code.
	 */
	public const BY_CODE = 'code';

	/**
	 * Order by the resolved status.
	 */
	public const BY_STATUS = 'status';

	/**
	 * Order by the expiry date, coupons that never expire last.
	 */
	public const BY_EXPIRES = 'expires';

	/**
	 * The column being sorted on.
	 *
	 * @var string
	 */
	public readonly string $by;

	/**
	 * Constructor.
	 *
	 * @param string $by         One of the class constants; anything else means the code.
	 * @param bool   $descending Whether to reverse the order.
	 */
	public function __construct( string $by = self::BY_CODE, public readonly bool $descending = false ) {
		$this->by = in_array( $by, array( self::BY_CODE, self::BY_STATUS, self::BY_EXPIRES ), true )
			? $by
			: self::BY_CODE;
	}

	/**
	 * Which direction a comparison should be multiplied by.
	 */
	public function direction(): int {
		return $this->descending ? -1 : 1;
	}
}
