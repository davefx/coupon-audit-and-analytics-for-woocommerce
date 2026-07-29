<?php
/**
 * Coupon identifier unit tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Domain\Coupon;

use DFX\CouponAAW\Domain\Coupon\CouponId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * A post ID with a type. The point is that a function taking a CouponId cannot
 * be handed a product ID by mistake.
 */
final class CouponIdTest extends TestCase {

	/**
	 * The wrapped value is available.
	 */
	public function test_it_carries_its_value(): void {
		$this->assertSame( 42, ( new CouponId( 42 ) )->value );
	}

	/**
	 * Identity is by value, not by object.
	 */
	public function test_two_ids_with_the_same_value_are_equal(): void {
		$this->assertTrue( ( new CouponId( 42 ) )->equals( new CouponId( 42 ) ) );
	}

	/**
	 * Different values are not equal.
	 */
	public function test_ids_with_different_values_are_not_equal(): void {
		$this->assertFalse( ( new CouponId( 42 ) )->equals( new CouponId( 43 ) ) );
	}

	/**
	 * WordPress post IDs start at 1. Anything lower is a bug upstream, and it
	 * is cheaper to catch it here than to watch a query silently return nothing.
	 *
	 * @dataProvider provide_invalid_ids
	 *
	 * @param int $value The rejected value.
	 */
	public function test_it_rejects_a_non_positive_id( int $value ): void {
		$this->expectException( InvalidArgumentException::class );

		new CouponId( $value );
	}

	/**
	 * Values that are not valid post IDs.
	 *
	 * @return array<string, array{int}>
	 */
	public static function provide_invalid_ids(): array {
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -1 ),
		);
	}
}
