<?php
/**
 * Coupon snapshot unit tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Domain\Coupon;

use DFX\CouponAAW\Domain\Coupon\CouponScope;
use DFX\CouponAAW\Tests\Fixtures\CouponSnapshotBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * An immutable read model of one coupon, as the repository found it. It holds
 * data and validates it; every derived question — status, orphanhood, overlap —
 * is answered by something else.
 */
final class CouponSnapshotTest extends TestCase {

	/**
	 * The values survive the trip through the constructor.
	 */
	public function test_it_carries_its_values(): void {
		$scope = new CouponScope( included_products: array( 10 ) );

		$coupon = CouponSnapshotBuilder::make()
			->with_id( 7 )
			->with_code( 'SUMMER24' )
			->limited_to( 5 )
			->used( 2 )
			->with_scope( $scope )
			->build();

		$this->assertSame( 7, $coupon->id->value );
		$this->assertSame( 'SUMMER24', $coupon->code );
		$this->assertTrue( $coupon->is_published );
		$this->assertSame( 5, $coupon->usage_limit );
		$this->assertSame( 2, $coupon->usage_count );
		$this->assertSame( $scope, $coupon->scope );
	}

	/**
	 * The optional dates really are optional.
	 */
	public function test_its_window_and_last_use_are_optional(): void {
		$coupon = CouponSnapshotBuilder::make()->build();

		$this->assertNull( $coupon->starts_at );
		$this->assertNull( $coupon->expires_at );
		$this->assertNull( $coupon->last_used_at );
		$this->assertNull( $coupon->usage_limit );
	}

	/**
	 * A coupon with no code cannot be matched against anything, and a snapshot
	 * that holds one is a repository bug worth failing loudly on.
	 */
	public function test_it_rejects_an_empty_code(): void {
		$this->expectException( InvalidArgumentException::class );

		CouponSnapshotBuilder::make()->with_code( '   ' )->build();
	}

	/**
	 * A negative usage count is impossible.
	 */
	public function test_it_rejects_a_negative_usage_count(): void {
		$this->expectException( InvalidArgumentException::class );

		CouponSnapshotBuilder::make()->used( -1 )->build();
	}

	/**
	 * WooCommerce stores "no limit" as an empty value; the repository is
	 * expected to translate that to null rather than to zero, because a limit
	 * of zero would otherwise read as "exhausted from birth".
	 */
	public function test_it_rejects_a_usage_limit_below_one(): void {
		$this->expectException( InvalidArgumentException::class );

		CouponSnapshotBuilder::make()->limited_to( 0 )->build();
	}
}
