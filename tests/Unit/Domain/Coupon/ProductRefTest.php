<?php
/**
 * Product reference unit tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Domain\Coupon;

use DFX\CouponAAW\Domain\Coupon\ProductRef;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The minimum a scope needs to know about a product to decide whether it
 * applies: which product, which categories, and whether it is on sale.
 */
final class ProductRefTest extends TestCase {

	/**
	 * The values are carried through.
	 */
	public function test_it_carries_its_values(): void {
		$product = new ProductRef( 10, array( 5, 6 ), true );

		$this->assertSame( 10, $product->id );
		$this->assertSame( array( 5, 6 ), $product->category_ids );
		$this->assertTrue( $product->is_on_sale );
	}

	/**
	 * A product with no categories, not on sale, is the common case.
	 */
	public function test_it_defaults_to_no_categories_and_not_on_sale(): void {
		$product = new ProductRef( 10 );

		$this->assertSame( array(), $product->category_ids );
		$this->assertFalse( $product->is_on_sale );
	}

	/**
	 * Category lists are normalised, so membership checks never depend on the
	 * order the repository happened to return.
	 */
	public function test_it_normalises_its_category_list(): void {
		$this->assertSame( array( 5, 6 ), ( new ProductRef( 10, array( 6, 5, 6 ) ) )->category_ids );
	}

	/**
	 * As with coupons, a non-positive post ID is a bug upstream.
	 */
	public function test_it_rejects_a_non_positive_id(): void {
		$this->expectException( InvalidArgumentException::class );

		new ProductRef( 0 );
	}
}
