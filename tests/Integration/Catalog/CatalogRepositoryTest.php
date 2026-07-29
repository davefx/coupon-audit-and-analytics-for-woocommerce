<?php
/**
 * Catalogue lookup integration tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Integration\Catalog;

use DFX\CouponAAW\Catalog\WcCatalogRepository;
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
	 * Create a published product, optionally in a category.
	 *
	 * @param string    $name       Its name.
	 * @param string    $price      Its price.
	 * @param list<int> $categories Categories it belongs to.
	 */
	private function product( string $name, string $price, array $categories = array() ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( $price );
		$product->set_status( 'publish' );

		if ( array() !== $categories ) {
			$product->set_category_ids( $categories );
		}

		return (int) $product->save();
	}

	/**
	 * Create a product category.
	 *
	 * @param string $name   Its name.
	 * @param int    $parent_id Its parent category, if any.
	 */
	private function category( string $name, int $parent_id = 0 ): int {
		$term = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent_id ) );

		$this->assertIsArray( $term );

		return (int) $term['term_id'];
	}

	/**
	 * The cheapest thing in the shop is what an unrestricted coupon reaches.
	 */
	public function test_it_finds_the_cheapest_product_in_the_shop(): void {
		$this->product( 'Expensive', '80.00' );
		$this->product( 'Cheap', '3.50' );

		$cheapest = $this->catalog->cheapest_overall();

		$this->assertNotNull( $cheapest );
		$this->assertSame( 350, $cheapest->amount );
	}

	/**
	 * Prices come back for a whole list at once, which is the only way the
	 * inventory screen can afford to ask.
	 */
	public function test_it_prices_many_products_at_once(): void {
		$dear  = $this->product( 'Expensive', '80.00' );
		$cheap = $this->product( 'Cheap', '3.50' );

		$prices = $this->catalog->prices( array( $dear, $cheap ) );

		$this->assertSame( 8000, $prices[ $dear ]->amount );
		$this->assertSame( 350, $prices[ $cheap ]->amount );
	}

	/**
	 * Asking for many prices costs one query however many are asked for. This is
	 * the whole reason the method takes a list, so it is asserted rather than
	 * assumed.
	 */
	public function test_pricing_many_products_costs_one_query(): void {
		$ids = array();

		for ( $i = 0; $i < 8; $i++ ) {
			$ids[] = $this->product( 'Product ' . $i, '10.00' );
		}

		wp_cache_flush();

		$before = get_num_queries();
		$prices = $this->catalog->prices( $ids );
		$after  = get_num_queries();

		$this->assertCount( 8, $prices );
		$this->assertSame( 1, $after - $before );
	}

	/**
	 * A product with no price is absent rather than free.
	 */
	public function test_an_unpriced_product_has_no_price(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Unpriced' );
		$product->set_status( 'publish' );
		$id = (int) $product->save();

		$this->assertSame( array(), $this->catalog->prices( array( $id ) ) );
	}

	/**
	 * Each category reports its own cheapest product, not the shop's.
	 */
	public function test_it_finds_the_cheapest_product_in_each_category(): void {
		$mugs   = $this->category( 'Mugs' );
		$plates = $this->category( 'Plates' );

		$this->product( 'Mug', '12.00', array( $mugs ) );
		$this->product( 'Cheap Mug', '4.00', array( $mugs ) );
		$this->product( 'Plate', '30.00', array( $plates ) );
		$this->product( 'Uncategorised Bargain', '0.50' );

		$cheapest = $this->catalog->cheapest_per_category( array( $mugs, $plates ) );

		$this->assertSame( 400, $cheapest[ $mugs ]->amount );
		$this->assertSame( 3000, $cheapest[ $plates ]->amount );
	}

	/**
	 * A parent category reaches what is filed under its children.
	 *
	 * A coupon restricted to "Kitchenware" applies to a mug filed only under
	 * "Kitchenware > Mugs", so the cheapest thing it reaches has to include that
	 * mug. This is asserted rather than assumed because the search matches on
	 * term ID, and matching a single ID literally would quietly miss every child.
	 */
	public function test_a_parent_category_includes_its_children(): void {
		$kitchenware = $this->category( 'Kitchenware' );
		$mugs        = $this->category( 'Mugs', $kitchenware );

		$this->product( 'Serving Dish', '40.00', array( $kitchenware ) );
		$this->product( 'Mug', '6.00', array( $mugs ) );

		$cheapest = $this->catalog->cheapest_per_category( array( $kitchenware ) );

		$this->assertSame( 600, $cheapest[ $kitchenware ]->amount );
	}

	/**
	 * A category ID of zero is not a category. WooCommerce drops an empty
	 * restriction, which would hand back the whole shop's cheapest product
	 * labelled as belonging to a category that does not exist.
	 */
	public function test_a_zero_category_is_not_the_whole_shop(): void {
		$this->product( 'Bargain', '0.50' );

		$this->assertSame( array(), $this->catalog->cheapest_per_category( array( 0 ) ) );
	}

	/**
	 * An empty category is absent, not zero.
	 */
	public function test_an_empty_category_is_absent(): void {
		$empty = $this->category( 'Empty' );

		$this->assertSame( array(), $this->catalog->cheapest_per_category( array( $empty ) ) );
	}

	/**
	 * A shop with nothing priced yields nothing, rather than zero. Not knowing
	 * is not the same as free.
	 */
	public function test_an_empty_catalogue_yields_nothing(): void {
		$this->assertNull( $this->catalog->cheapest_overall() );
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
