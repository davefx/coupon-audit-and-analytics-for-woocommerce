<?php
/**
 * Catalogue lookups against WooCommerce.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Catalog;

use DFX\CouponAAW\Domain\Profit\Money;
use WC_Product;
use WP_Term;

/**
 * Reads product and category detail from WooCommerce.
 *
 * The cheapest-price lookups are the reason this class exists rather than a loop.
 * Asking "is anything this coupon reaches cheaper than the discount" by loading
 * every product in every referenced category — which is how the prior art did
 * it — costs a full product object per row, and a shop with a thousand-product
 * category pays that on every page load. Ordering WooCommerce's own product
 * query by price and taking one row is backed by `wc_product_meta_lookup`, so
 * the same question costs a single indexed lookup.
 *
 * Everything here is asked in bulk, because the caller has a whole page of
 * coupons to answer for and asking once per coupon is what made the page slow
 * in the first place.
 */
final class WcCatalogRepository implements CatalogRepositoryInterface {

	/**
	 * Constructor.
	 *
	 * @param string $currency The store's currency.
	 * @param int    $decimals Places in the currency's minor unit.
	 */
	public function __construct(
		private readonly string $currency,
		private readonly int $decimals
	) {}

	/**
	 * Describe the given products.
	 *
	 * @param list<int> $ids Product IDs.
	 *
	 * @return array<int, ProductDetail>
	 */
	public function products( array $ids ): array {
		$details = array();

		foreach ( array_unique( $ids ) as $id ) {
			$product = wc_get_product( $id );

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$details[ (int) $id ] = new ProductDetail(
				(int) $id,
				$product->get_name(),
				$this->price_of( $product ),
				$product->is_purchasable() && $product->is_in_stock(),
				$this->unavailable_because( $product )
			);
		}

		return $details;
	}

	/**
	 * Name the given product categories.
	 *
	 * @param list<int> $ids Category term IDs.
	 *
	 * @return array<int, string>
	 */
	public function category_names( array $ids ): array {
		$names = array();

		foreach ( array_unique( $ids ) as $id ) {
			$term = get_term( $id, 'product_cat' );

			if ( $term instanceof WP_Term ) {
				$names[ (int) $id ] = $term->name;
			}
		}

		return $names;
	}

	/**
	 * The price of each of the given products.
	 *
	 * @param list<int> $ids Product IDs.
	 *
	 * @return array<int, Money>
	 */
	public function prices( array $ids ): array {
		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );

		if ( array() === $ids ) {
			return array();
		}

		/*
		 * One query for every price, through WordPress's own meta cache. The
		 * alternative — a product object each — costs a query and a few hundred
		 * kilobytes per product, and nothing here needs a product object: it
		 * needs a number.
		 */
		update_meta_cache( 'post', $ids );

		$prices = array();

		foreach ( $ids as $id ) {
			$price = get_post_meta( $id, '_price', true );

			if ( is_numeric( $price ) ) {
				$prices[ $id ] = Money::from_decimal( (float) $price, $this->currency, $this->decimals );
			}
		}

		return $prices;
	}

	/**
	 * The cheapest product in each of the given categories.
	 *
	 * @param list<int> $ids Category term IDs.
	 *
	 * @return array<int, Money>
	 */
	public function cheapest_per_category( array $ids ): array {
		// A zero would be dropped by WooCommerce as an absent restriction, and the
		// shop-wide cheapest would come back labelled as that category's.
		$ids = array_values( array_filter( array_unique( array_map( 'absint', $ids ) ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$winners = array();

		foreach ( $ids as $id ) {
			/*
			 * Matched on the term ID rather than the slug. WooCommerce accepts
			 * either, but a slug has to be resolved back to an ID before the
			 * search can run — two further queries per category, which came to
			 * more than the searches themselves. Both routes include the products
			 * filed under a category's children, so a coupon restricted to a
			 * parent category still reaches everything it reaches in the shop.
			 */
			$winner = $this->cheapest_id( array( 'product_category_id' => array( $id ) ) );

			if ( null !== $winner ) {
				$winners[ $id ] = $winner;
			}
		}

		// The winners are priced together, so the number of categories costs
		// searches but not price lookups.
		$prices   = $this->prices( array_values( $winners ) );
		$cheapest = array();

		foreach ( $winners as $term_id => $product_id ) {
			if ( isset( $prices[ $product_id ] ) ) {
				$cheapest[ $term_id ] = $prices[ $product_id ];
			}
		}

		return $cheapest;
	}

	/**
	 * The cheapest product in the shop.
	 */
	public function cheapest_overall(): ?Money {
		$winner = $this->cheapest_id( array() );

		return null === $winner ? null : ( $this->prices( array( $winner ) )[ $winner ] ?? null );
	}

	/**
	 * Which product is the cheapest one matching the given restriction.
	 *
	 * Asked of WooCommerce's own product query rather than of the database, so
	 * there is no hand-assembled SQL to get wrong or to argue with three separate
	 * checkers about — and so that a category means what WooCommerce says it
	 * means, including the products filed under its children.
	 *
	 * Only the ID is asked for. Fetching the product itself is what made this
	 * expensive: WooCommerce answers a one-row search by loading the post, its
	 * meta and its terms, so a handful of categories became a hundred queries.
	 * The prices are read separately, all at once.
	 *
	 * @param array<string, mixed> $restriction What limits the search.
	 */
	private function cheapest_id( array $restriction ): ?int {
		$ids = wc_get_products(
			array_merge(
				array(
					'limit'    => 1,
					'status'   => 'publish',
					'return'   => 'ids',

					/*
					 * Sorted on the stored price rather than by passing
					 * `orderby => 'price'`, which WooCommerce accepts and
					 * silently ignores: it returns whatever was created first.
					 * That would have made every discount check quietly compare
					 * against the wrong product.
					 */
					'orderby'  => 'meta_value_num',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- _price is indexed by WooCommerce, and this reads one row.
					'meta_key' => '_price',
					'order'    => 'ASC',
				),
				$restriction
			)
		);

		if ( ! is_array( $ids ) || array() === $ids ) {
			return null;
		}

		$id = reset( $ids );

		return is_numeric( $id ) ? (int) $id : null;
	}

	/**
	 * A product's price, or null where it has none.
	 *
	 * @param WC_Product $product The product.
	 */
	private function price_of( WC_Product $product ): ?Money {
		$price = $product->get_price();

		if ( ! is_numeric( $price ) ) {
			return null;
		}

		return Money::from_decimal( (float) $price, $this->currency, $this->decimals );
	}

	/**
	 * Why a customer could not buy this product, if they could not.
	 *
	 * @param WC_Product $product The product.
	 */
	private function unavailable_because( WC_Product $product ): ?string {
		if ( ! $product->is_in_stock() ) {
			return __( 'out of stock', 'coupon-audit-and-analytics-for-woocommerce' );
		}

		if ( '' === (string) $product->get_price() ) {
			return __( 'no price set', 'coupon-audit-and-analytics-for-woocommerce' );
		}

		if ( ! $product->is_purchasable() ) {
			return __( 'not purchasable', 'coupon-audit-and-analytics-for-woocommerce' );
		}

		if ( ! $product->is_visible() ) {
			return __( 'hidden from the catalogue', 'coupon-audit-and-analytics-for-woocommerce' );
		}

		return null;
	}
}
