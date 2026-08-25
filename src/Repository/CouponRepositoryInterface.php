<?php
/**
 * Coupon repository contract.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Repository;

use DFX\CouponAAW\Domain\Coupon\CouponId;
use DFX\CouponAAW\Domain\Coupon\CouponProjection;
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
	 * Every coupon, reduced to the scalars the audit needs in bulk.
	 *
	 * The cheap counterpart of all(). Building a snapshot per coupon costs about
	 * a second and 27 MB per thousand, which puts the audit screen out of reach
	 * of the shops it helps most; almost everything asked of the whole inventory
	 * needs only columns. See docs/PERFORMANCE.md.
	 *
	 * @return list<CouponProjection>
	 */
	public function project(): array;

	/**
	 * Build only the coupons named, in the order they were named.
	 *
	 * The expensive half of `all()` is building a `CouponSnapshot` — reading the
	 * meta, resolving the scope, pricing it. A screen showing twenty rows needs
	 * twenty of those, not twenty-six thousand, and this is how it asks.
	 *
	 * IDs that are not coupons are skipped rather than raising: they reach here
	 * from a projection read moments earlier, and a coupon deleted in between is
	 * a race, not a fault.
	 *
	 * @param list<CouponId> $ids The coupons wanted.
	 *
	 * @return list<CouponSnapshot>
	 */
	public function some( array $ids ): array;

	/**
	 * The codes of the coupons named, keyed by ID.
	 *
	 * For callers that want a coupon's name and nothing else. The margin screen
	 * is the one: building a `CouponSnapshot` to read a code is a `WC_Coupon`
	 * per row, and its export walks a whole window of them.
	 *
	 * A coupon that no longer exists is absent from the result rather than
	 * present with an empty code. The screen shows figures for coupons deleted
	 * since their orders were placed, and "no code" is what it has to say about
	 * them — an empty string would read as a coupon named nothing.
	 *
	 * @param list<CouponId> $ids The coupons wanted.
	 *
	 * @return array<int, string>
	 */
	public function codes( array $ids ): array;

	/**
	 * How many coupons exist, without loading them.
	 */
	public function count(): int;
}
