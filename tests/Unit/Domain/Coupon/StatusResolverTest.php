<?php
/**
 * Coupon status resolution unit tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Domain\Coupon;

use DFX\CouponAAW\Domain\Coupon\CouponStatus;
use DFX\CouponAAW\Domain\Coupon\StatusResolver;
use DFX\CouponAAW\Tests\Fixtures\CouponSnapshotBuilder;
use DFX\CouponAAW\Tests\Fixtures\FrozenClock;
use PHPUnit\Framework\TestCase;

/**
 * Status is derived, never stored (§8.1). Because it depends on the current
 * time, the clock is injected — which is what lets the boundary cases below be
 * written at all.
 */
final class StatusResolverTest extends TestCase {

	/**
	 * A resolver whose clock is frozen at a given instant.
	 *
	 * @param string $now The instant the clock reports.
	 */
	private function resolver( string $now = '2026-07-28' ): StatusResolver {
		return new StatusResolver( FrozenClock::at( $now ) );
	}

	/**
	 * The example from §10.5, which this implementation exists to satisfy.
	 */
	public function test_coupon_with_past_expiry_is_expired(): void {
		$coupon = CouponSnapshotBuilder::make()
			->published()
			->expiring( '2026-07-01' )
			->build();

		$this->assertSame( CouponStatus::EXPIRED, $this->resolver()->resolve( $coupon ) );
	}

	/**
	 * A published coupon with no window and no limit is simply active.
	 */
	public function test_a_published_coupon_without_restrictions_is_active(): void {
		$coupon = CouponSnapshotBuilder::make()->published()->build();

		$this->assertSame( CouponStatus::ACTIVE, $this->resolver()->resolve( $coupon ) );
	}

	/**
	 * An unpublished post is inactive whatever its dates say.
	 */
	public function test_an_unpublished_coupon_is_inactive(): void {
		$coupon = CouponSnapshotBuilder::make()->unpublished()->build();

		$this->assertSame( CouponStatus::INACTIVE, $this->resolver()->resolve( $coupon ) );
	}

	/**
	 * A start date in the future means the coupon has not opened yet.
	 */
	public function test_a_future_start_date_is_scheduled(): void {
		$coupon = CouponSnapshotBuilder::make()->starting( '2026-08-01' )->build();

		$this->assertSame( CouponStatus::SCHEDULED, $this->resolver()->resolve( $coupon ) );
	}

	/**
	 * A start date already passed does not hold the coupon back.
	 */
	public function test_a_past_start_date_is_active(): void {
		$coupon = CouponSnapshotBuilder::make()->starting( '2026-07-01' )->build();

		$this->assertSame( CouponStatus::ACTIVE, $this->resolver()->resolve( $coupon ) );
	}

	/**
	 * An expiry still ahead does not expire anything.
	 */
	public function test_a_future_expiry_is_active(): void {
		$coupon = CouponSnapshotBuilder::make()->expiring( '2026-08-01' )->build();

		$this->assertSame( CouponStatus::ACTIVE, $this->resolver()->resolve( $coupon ) );
	}

	/**
	 * At the exact expiry instant the coupon is still valid.
	 *
	 * WooCommerce compares with a strict `>` when deciding that a coupon has
	 * expired, and a coupon the storefront still accepts must not be reported
	 * as dead by this plugin. The whole reason the clock is injected is so this
	 * case can be pinned down rather than assumed.
	 */
	public function test_a_coupon_is_not_expired_at_the_exact_expiry_instant(): void {
		$coupon = CouponSnapshotBuilder::make()->expiring( '2026-07-28' )->build();

		$this->assertSame( CouponStatus::ACTIVE, $this->resolver( '2026-07-28' )->resolve( $coupon ) );
	}

	/**
	 * One second later it is expired.
	 */
	public function test_a_coupon_is_expired_one_second_after_its_expiry(): void {
		$coupon = CouponSnapshotBuilder::make()->expiring( '2026-07-28 00:00:00' )->build();

		$this->assertSame(
			CouponStatus::EXPIRED,
			$this->resolver( '2026-07-28 00:00:01' )->resolve( $coupon )
		);
	}

	/**
	 * Reaching the usage limit exhausts the coupon.
	 */
	public function test_a_coupon_at_its_usage_limit_is_exhausted(): void {
		$coupon = CouponSnapshotBuilder::make()->limited_to( 5 )->used( 5 )->build();

		$this->assertSame( CouponStatus::EXHAUSTED, $this->resolver()->resolve( $coupon ) );
	}

	/**
	 * Going over the limit, which concurrent checkouts can do, is still
	 * exhausted rather than something stranger.
	 */
	public function test_a_coupon_past_its_usage_limit_is_exhausted(): void {
		$coupon = CouponSnapshotBuilder::make()->limited_to( 5 )->used( 6 )->build();

		$this->assertSame( CouponStatus::EXHAUSTED, $this->resolver()->resolve( $coupon ) );
	}

	/**
	 * Below the limit it is active.
	 */
	public function test_a_coupon_below_its_usage_limit_is_active(): void {
		$coupon = CouponSnapshotBuilder::make()->limited_to( 5 )->used( 4 )->build();

		$this->assertSame( CouponStatus::ACTIVE, $this->resolver()->resolve( $coupon ) );
	}

	/**
	 * No limit means no amount of use exhausts it.
	 */
	public function test_a_coupon_without_a_usage_limit_is_never_exhausted(): void {
		$coupon = CouponSnapshotBuilder::make()->used( 10000 )->build();

		$this->assertSame( CouponStatus::ACTIVE, $this->resolver()->resolve( $coupon ) );
	}

	/**
	 * Expiry beats exhaustion. Both make a coupon unusable, but only one can be
	 * undone by editing the coupon: raising the usage limit revives an exhausted
	 * coupon, while an expired one stays dead until its date moves. Reporting
	 * the condition the user cannot lift is the more useful of the two.
	 */
	public function test_expiry_takes_precedence_over_exhaustion(): void {
		$coupon = CouponSnapshotBuilder::make()
			->expiring( '2026-07-01' )
			->limited_to( 5 )
			->used( 5 )
			->build();

		$this->assertSame( CouponStatus::EXPIRED, $this->resolver()->resolve( $coupon ) );
	}

	/**
	 * Being unpublished beats everything: the coupon is off, and why it would
	 * otherwise be off does not matter.
	 */
	public function test_being_unpublished_takes_precedence_over_expiry(): void {
		$coupon = CouponSnapshotBuilder::make()->unpublished()->expiring( '2026-07-01' )->build();

		$this->assertSame( CouponStatus::INACTIVE, $this->resolver()->resolve( $coupon ) );
	}

	/**
	 * Exhaustion beats scheduling: a coupon that can never be redeemed is not
	 * meaningfully "coming soon".
	 */
	public function test_exhaustion_takes_precedence_over_scheduling(): void {
		$coupon = CouponSnapshotBuilder::make()
			->starting( '2026-08-01' )
			->limited_to( 1 )
			->used( 1 )
			->build();

		$this->assertSame( CouponStatus::EXHAUSTED, $this->resolver()->resolve( $coupon ) );
	}

	/**
	 * Only an active coupon is usable, and the enum says so rather than every
	 * caller re-deriving it.
	 */
	public function test_only_the_active_status_is_usable(): void {
		$this->assertTrue( CouponStatus::ACTIVE->is_usable() );

		foreach ( array( CouponStatus::SCHEDULED, CouponStatus::EXPIRED, CouponStatus::EXHAUSTED, CouponStatus::INACTIVE ) as $status ) {
			$this->assertFalse( $status->is_usable(), $status->value . ' must not be usable' );
		}
	}
}
