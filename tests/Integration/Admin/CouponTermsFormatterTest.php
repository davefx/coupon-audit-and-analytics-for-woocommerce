<?php
/**
 * Coupon terms formatting tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Integration\Admin;

use DFX\CouponAAW\Admin\CouponTermsFormatter;
use DFX\CouponAAW\Catalog\WcCatalogRepository;
use DFX\CouponAAW\Domain\Coupon\CouponScope;
use DFX\CouponAAW\Domain\Coupon\CouponTerms;
use DFX\CouponAAW\Domain\Coupon\DiscountAmount;
use DFX\CouponAAW\Domain\Profit\Money;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * Putting a coupon's terms into words.
 *
 * The point of this class is saying *what*, not *whether*: "restricted" tells a
 * shop owner nothing they can act on. So what is tested is that the products and
 * categories are named, that things a customer cannot buy are marked as such,
 * and that a coupon pointing at something deleted says so rather than rendering
 * a blank.
 *
 * It needs WooCommerce for prices and terms, so it runs here rather than in the
 * unit suite.
 */
final class CouponTermsFormatterTest extends WP_UnitTestCase {

	/**
	 * The subject under test.
	 *
	 * @var CouponTermsFormatter
	 */
	private CouponTermsFormatter $formatter;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->formatter = new CouponTermsFormatter(
			new WcCatalogRepository( get_woocommerce_currency(), 2 )
		);
	}

	/**
	 * An amount in the store's currency.
	 *
	 * @param int $minor The amount in minor units.
	 */
	private function money( int $minor ): Money {
		return new Money( $minor, get_woocommerce_currency() );
	}

	/**
	 * Terms with a fixed discount, varied by named argument.
	 *
	 * @param Money|null $minimum Lowest basket total.
	 * @param Money|null $maximum Highest basket total.
	 */
	private function terms( ?Money $minimum = null, ?Money $maximum = null ): CouponTerms {
		return new CouponTerms(
			DiscountAmount::fixed( $this->money( 1000 ) ),
			$minimum,
			$maximum
		);
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
	 * A percentage reads as a percentage.
	 */
	public function test_it_renders_a_percentage(): void {
		$terms = new CouponTerms( DiscountAmount::percentage( 12.5 ) );

		$this->assertStringContainsString( '12.50', $this->formatter->amount( $terms ) );
		$this->assertStringContainsString( '%', $this->formatter->amount( $terms ) );
	}

	/**
	 * A fixed sum reads as money, in the store's currency.
	 */
	public function test_it_renders_a_fixed_sum_as_money(): void {
		$this->assertStringContainsString( '10', $this->formatter->amount( $this->terms() ) );
	}

	/**
	 * A minimum on its own, a maximum on its own, and both together each read
	 * differently — and a coupon with neither says "any" rather than nothing.
	 */
	public function test_it_phrases_the_required_spend(): void {
		$this->assertStringContainsString(
			'or more',
			$this->formatter->required_spend( $this->terms( minimum: $this->money( 5000 ) ) )
		);

		$this->assertStringContainsString(
			'up to',
			$this->formatter->required_spend( $this->terms( maximum: $this->money( 9000 ) ) )
		);

		$this->assertStringContainsString(
			' to ',
			$this->formatter->required_spend(
				$this->terms( minimum: $this->money( 5000 ), maximum: $this->money( 9000 ) )
			)
		);

		$this->assertStringContainsString( 'any', $this->formatter->required_spend( $this->terms() ) );
	}

	/**
	 * Usage reads as "used of allowed", with an infinity sign where there is no
	 * cap — a blank would read as "none allowed".
	 */
	public function test_it_renders_usage_against_its_cap(): void {
		$this->assertStringContainsString( '∞', $this->formatter->usage( 3, null, $this->terms() ) );
		$this->assertStringContainsString( '10', $this->formatter->usage( 3, 10, $this->terms() ) );
	}

	/**
	 * Per-customer and per-basket caps are named when set.
	 */
	public function test_it_names_the_per_customer_and_per_basket_caps(): void {
		$terms = new CouponTerms(
			DiscountAmount::percentage( 10.0 ),
			usage_limit_per_user: 2,
			limit_usage_to_items: 5
		);

		$rendered = $this->formatter->usage( 0, null, $terms );

		$this->assertStringContainsString( 'per customer', $rendered );
		$this->assertStringContainsString( 'per basket', $rendered );
	}

	/**
	 * The flags that are simply on or off.
	 */
	public function test_it_renders_the_flags(): void {
		$terms = new CouponTerms(
			DiscountAmount::percentage( 10.0 ),
			grants_free_shipping: true,
			is_individual_use: true,
			email_restrictions: array( 'someone@example.com' )
		);

		$rendered = $this->formatter->flags( $terms );

		$this->assertStringContainsString( 'Free shipping', $rendered );
		$this->assertStringContainsString( 'Individual use', $rendered );
		$this->assertStringContainsString( 'someone@example.com', $rendered );
	}

	/**
	 * A coupon with no flags shows a dash, not an empty cell.
	 */
	public function test_a_coupon_without_flags_shows_a_dash(): void {
		$this->assertStringContainsString( '&mdash;', $this->formatter->flags( $this->terms() ) );
	}

	/**
	 * An unrestricted coupon says so in a word.
	 */
	public function test_an_unrestricted_scope_reads_as_everything(): void {
		$this->assertSame( 'Everything', $this->formatter->scope( CouponScope::universal() ) );
	}

	/**
	 * Products are named and priced, which is the whole point: it lets a shop
	 * owner see whether the restriction is the one they meant.
	 */
	public function test_it_names_and_prices_the_products(): void {
		$id = $this->product( 'Stoneware Mug', '12.00' );

		$rendered = $this->formatter->scope( new CouponScope( included_products: array( $id ) ) );

		$this->assertStringContainsString( 'Stoneware Mug', $rendered );
		$this->assertStringContainsString( '12', $rendered );
		$this->assertStringContainsString( 'Only', $rendered );
	}

	/**
	 * Categories are named too.
	 */
	public function test_it_names_the_categories(): void {
		$term = wp_insert_term( 'Kitchenware', 'product_cat' );

		$this->assertIsArray( $term );

		$rendered = $this->formatter->scope(
			new CouponScope( included_categories: array( (int) $term['term_id'] ) )
		);

		$this->assertStringContainsString( 'Kitchenware', $rendered );
	}

	/**
	 * Exclusions are named as exclusions rather than lumped in with inclusions,
	 * which would invert what the coupon does.
	 */
	public function test_exclusions_read_as_exclusions(): void {
		$id = $this->product( 'Excluded Mug', '12.00' );

		$rendered = $this->formatter->scope( new CouponScope( excluded_products: array( $id ) ) );

		$this->assertStringContainsString( 'Never', $rendered );
		$this->assertStringContainsString( 'Excluded Mug', $rendered );
	}

	/**
	 * Sale-item exclusion is a restriction like any other and is said out loud.
	 */
	public function test_it_says_when_sale_items_are_excluded(): void {
		$id = $this->product( 'Mug', '12.00' );

		$rendered = $this->formatter->scope(
			new CouponScope( included_products: array( $id ), excludes_sale_items: true )
		);

		$this->assertStringContainsString( 'Not on sale items', $rendered );
	}

	/**
	 * A product a customer cannot buy is marked, because a coupon restricted to
	 * something out of stock is doing nothing at all — and that is invisible
	 * anywhere else in WooCommerce.
	 */
	public function test_a_product_that_cannot_be_bought_is_marked(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Sold Out' );
		$product->set_regular_price( '9.00' );
		$product->set_stock_status( 'outofstock' );
		$product->set_status( 'publish' );
		$id = (int) $product->save();

		$rendered = $this->formatter->scope( new CouponScope( included_products: array( $id ) ) );

		$this->assertStringContainsString( 'dfxcaaw-gone', $rendered );
		$this->assertStringContainsString( 'out of stock', $rendered );
	}

	/**
	 * A coupon pointing at a deleted product says so, naming the ID.
	 *
	 * Rendering a blank would read as "applies to everything", which is the
	 * opposite of the truth: it applies to nothing.
	 */
	public function test_a_deleted_product_is_named_as_deleted(): void {
		$rendered = $this->formatter->scope( new CouponScope( included_products: array( 999999 ) ) );

		$this->assertStringContainsString( 'deleted product', $rendered );
		$this->assertStringContainsString( '999999', $rendered );
	}

	/**
	 * The same for a category that no longer exists.
	 */
	public function test_a_deleted_category_is_named_as_deleted(): void {
		$rendered = $this->formatter->scope( new CouponScope( included_categories: array( 999999 ) ) );

		$this->assertStringContainsString( 'deleted category', $rendered );
	}

	/**
	 * A product name is escaped before it reaches the page. Product names are
	 * written by whoever runs the shop, and this cell renders raw HTML around
	 * them.
	 *
	 * An ampersand rather than a script tag: WordPress strips tags from a post
	 * title on the way in, so a test using one would pass whether or not this
	 * class escaped anything. An ampersand survives storage and still has to be
	 * encoded.
	 */
	public function test_it_escapes_a_product_name(): void {
		$id = $this->product( 'Mug & Spoon', '5.00' );

		$rendered = $this->formatter->scope( new CouponScope( included_products: array( $id ) ) );

		$this->assertStringContainsString( 'Mug &amp; Spoon', $rendered );
	}

	/**
	 * The reason a product cannot be bought goes into a title attribute, so it
	 * is escaped for that context rather than for text.
	 */
	public function test_it_escapes_the_unavailability_reason_as_an_attribute(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Sold Out' );
		$product->set_regular_price( '9.00' );
		$product->set_stock_status( 'outofstock' );
		$product->set_status( 'publish' );
		$id = (int) $product->save();

		$rendered = $this->formatter->scope( new CouponScope( included_products: array( $id ) ) );

		$this->assertMatchesRegularExpression( '/title="[^"<>]*"/', $rendered );
	}

	/**
	 * And an email restriction, which is entered free-hand on the coupon screen.
	 */
	public function test_it_escapes_an_email_restriction(): void {
		$terms = new CouponTerms(
			DiscountAmount::percentage( 10.0 ),
			email_restrictions: array( '<script>alert(1)</script>' )
		);

		$this->assertStringNotContainsString( '<script>', $this->formatter->flags( $terms ) );
	}
}
