<?php
/**
 * Catalogue lookup integration tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Integration\Catalog;

use DFX\CouponAAW\Catalog\WcCatalogRepository;
use DFX\CouponAAW\Domain\Coupon\CouponScope;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * The cheapest-price lookup is what the discount check rests on, and it can
 * only be verified against a real catalogue.
 */
final class CatalogRepositoryTest extends WP_UnitTestCase {

	/**
	 * The subject under test.
	 *
	 * @var WcCatalogRepository
	 */
	private WcCatalogRepository $catalog;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->catalog = new WcCatalogRepository( get_woocommerce_currency(), 2 );
	}

	/**
	 * Create a published product.
	 *
	 * @param string $name  Its name.
	 * @param string $price Its price.
	 */
	private function product( string $name, string $price ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( $price );
		$product->set_status( 'publish' );

		return (int) $product->save();
	}

	/**
	 * An unrestricted coupon reaches everything, so the cheapest thing in the
	 * shop is the cheapest thing it can be applied to.
	 */
	public function test_an_unrestricted_scope_finds_the_cheapest_product(): void {
		$this->product( 'Expensive', '80.00' );
		$this->product( 'Cheap', '3.50' );

		$cheapest = $this->catalog->cheapest_in_scope( CouponScope::universal() );

		$this->assertNotNull( $cheapest );
		$this->assertSame( 350, $cheapest->amount );
	}

	/**
	 * A scope limited to particular products looks only at those.
	 */
	public function test_it_looks_only_at_the_named_products(): void {
		$dear = $this->product( 'Expensive', '80.00' );
		$this->product( 'Cheap', '3.50' );

		$cheapest = $this->catalog->cheapest_in_scope(
			new CouponScope( included_products: array( $dear ) )
		);

		$this->assertNotNull( $cheapest );
		$this->assertSame( 8000, $cheapest->amount );
	}

	/**
	 * An excluded product is not the cheapest thing a coupon reaches, however
	 * cheap it is.
	 */
	public function test_an_excluded_product_is_not_considered(): void {
		$this->product( 'Middling', '20.00' );
		$cheap = $this->product( 'Cheap', '3.50' );

		$cheapest = $this->catalog->cheapest_in_scope(
			new CouponScope( excluded_products: array( $cheap ) )
		);

		$this->assertNotNull( $cheapest );
		$this->assertSame( 2000, $cheapest->amount );
	}

	/**
	 * A shop with nothing priced yields nothing, rather than zero. Not knowing
	 * is not the same as free.
	 */
	public function test_an_empty_catalogue_yields_nothing(): void {
		$this->assertNull( $this->catalog->cheapest_in_scope( CouponScope::universal() ) );
	}

	/**
	 * Products are described well enough to name on screen.
	 */
	public function test_it_describes_products(): void {
		$id = $this->product( 'Stoneware Mug', '12.00' );

		$details = $this->catalog->products( array( $id ) );

		$this->assertArrayHasKey( $id, $details );
		$this->assertSame( 'Stoneware Mug', $details[ $id ]->name );
		$this->assertSame( 1200, $details[ $id ]->price?->amount );
		$this->assertTrue( $details[ $id ]->is_available );
	}

	/**
	 * A product a customer could not buy says why, which is the point of asking.
	 */
	public function test_it_says_why_a_product_cannot_be_bought(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Sold Out' );
		$product->set_regular_price( '9.00' );
		$product->set_stock_status( 'outofstock' );
		$id = (int) $product->save();

		$details = $this->catalog->products( array( $id ) );

		$this->assertFalse( $details[ $id ]->is_available );
		$this->assertNotNull( $details[ $id ]->unavailable );
	}

	/**
	 * A product that no longer exists is simply absent, not an error.
	 */
	public function test_a_missing_product_is_absent(): void {
		$this->assertSame( array(), $this->catalog->products( array( 999999 ) ) );
	}
}
