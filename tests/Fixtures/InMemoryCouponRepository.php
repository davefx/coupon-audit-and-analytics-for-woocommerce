<?php
/**
 * In-memory coupon repository.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Fixtures;

use DFX\CouponAAW\Domain\Coupon\CouponId;
use DFX\CouponAAW\Domain\Coupon\CouponSnapshot;
use DFX\CouponAAW\Repository\CouponRepositoryInterface;

/**
 * The repository seam of §10.4, filled in for the unit suite.
 *
 * Everything above the repository can therefore be tested without a database,
 * which is the whole reason the interface exists.
 *
 * Not final, so a test can extend it to observe how it is called.
 */
class InMemoryCouponRepository implements CouponRepositoryInterface {

	/**
	 * Constructor.
	 *
	 * @param list<CouponSnapshot> $coupons The stored coupons.
	 */
	public function __construct( protected array $coupons = array() ) {}

	/**
	 * Find one coupon.
	 *
	 * @param CouponId $id The coupon to load.
	 */
	public function find( CouponId $id ): ?CouponSnapshot {
		foreach ( $this->coupons as $coupon ) {
			if ( $coupon->id->equals( $id ) ) {
				return $coupon;
			}
		}

		return null;
	}

	/**
	 * Every stored coupon.
	 *
	 * @return list<CouponSnapshot>
	 */
	public function all(): array {
		return $this->coupons;
	}

	/**
	 * How many coupons are stored.
	 */
	public function count(): int {
		return count( $this->coupons );
	}
}
