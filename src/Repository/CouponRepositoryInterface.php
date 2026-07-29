<?php
/**
 * Coupon repository contract.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Repository;

use DFX\CouponAAW\Domain\Coupon\CouponId;
use DFX\CouponAAW\Domain\Coupon\CouponSnapshot;

/**
 * Reads coupons out of wherever they happen to live.
 *
 * The domain depends on this and never on WordPress, which is what lets status,
 * scope and orphan logic be tested without a database (§10.4).
 */
interface CouponRepositoryInterface {

	/**
	 * Find one coupon.
	 *
	 * @param CouponId $id The coupon to load.
	 *
	 * @return CouponSnapshot|null Null when no coupon with that ID exists.
	 */
	public function find( CouponId $id ): ?CouponSnapshot;

	/**
	 * Every coupon in the store.
	 *
	 * @return list<CouponSnapshot>
	 */
	public function all(): array;

	/**
	 * How many coupons exist, without loading them.
	 */
	public function count(): int;
}
