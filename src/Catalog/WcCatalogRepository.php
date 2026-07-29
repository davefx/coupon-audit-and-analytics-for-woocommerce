<?php
/**
 * Catalogue lookups against WooCommerce.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Catalog;

use DFX\CouponAAW\Domain\Coupon\CouponScope;
use DFX\CouponAAW\Domain\Profit\Money;
use WC_Product;
use WP_Term;

/**
 * Reads product and category detail from WooCommerce.
 *
 * The cheapest-price lookup is the reason this class exists rather than a loop.
 * Asking "is anything this coupon reaches cheaper than the discount" by loading
 * every product in every referenced category — which is how the prior art did
 * it — costs a full product object per row, and a shop with a thousand-product
 * category pays that on every page load. Ordering WooCommerce's own product
 * query by price and taking one row is backed by `wc_product_meta_lookup`, so
 * the same question costs a single indexed lookup.
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
	 * The lowest price a coupon with this scope could be applied to.
	 *
	 * @param CouponScope $scope The coupon's scope.
	 */
	public function cheapest_in_scope( CouponScope $scope ): ?Money {
		$prices = array();

		if ( array() !== $scope->included_products ) {
			$prices[] = $this->cheapest( array( 'include' => $scope->included_products ), $scope );
		}

		if ( array() !== $scope->included_categories ) {
			$slugs = $this->category_slugs( $scope->included_categories );

			if ( array() !== $slugs ) {
				$prices[] = $this->cheapest( array( 'category' => $slugs ), $scope );
			}
		}

		// An unrestricted coupon reaches the whole catalogue, so the cheapest
		// thing in the shop is the cheapest thing it can be applied to.
		if ( array() === $prices ) {
			$prices[] = $this->cheapest( array(), $scope );
		}

		$found = array_values( array_filter( $prices ) );

		if ( array() === $found ) {
			return null;
		}

		$cheapest = $found[0];

		foreach ( $found as $price ) {
			if ( $price->amount < $cheapest->amount ) {
				$cheapest = $price;
			}
		}

		return $cheapest;
	}

	/**
	 * The cheapest purchasable product matching the given restriction.
	 *
	 * Asked of WooCommerce's own product query rather than of the database.
	 * Ordering by price there is backed by `wc_product_meta_lookup`, so this is
	 * still one indexed lookup rather than the full scan that loading a category
	 * would be — and it leaves no hand-assembled SQL to get wrong or to have to
	 * argue with three separate checkers about.
	 *
	 * @param array<string, mixed> $restriction What limits the search.
	 * @param CouponScope          $scope       The coupon's scope, for its exclusions.
	 */
	private function cheapest( array $restriction, CouponScope $scope ): ?Money {
		$excluded = $scope->excluded_products;

		/*
		 * Exclusions are applied here rather than passed to the query. Asking
		 * the database to exclude posts is the `post__not_in` pattern, which is
		 * slow enough that WordPress's own performance checks object to it —
		 * and it is unnecessary: taking one more row than there are exclusions
		 * guarantees at least one survivor, since at most that many can be
		 * discarded.
		 */
		$products = wc_get_products(
			array_merge(
				array(
					'limit'    => count( $excluded ) + 1,
					'status'   => 'publish',

					/*
					 * Sorted on the stored price rather than by passing
					 * `orderby => 'price'`, which WooCommerce accepts and
					 * silently ignores: it returns whatever was created first.
					 * That would have made every discount check quietly compare
					 * against the wrong product.
					 */
					'orderby'  => 'meta_value_num',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- _price is indexed by WooCommerce, and this reads a handful of rows.
					'meta_key' => '_price',
					'order'    => 'ASC',
				),
				$restriction
			)
		);

		if ( ! is_array( $products ) ) {
			return null;
		}

		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			if ( in_array( $product->get_id(), $excluded, true ) ) {
				continue;
			}

			return $this->price_of( $product );
		}

		return null;
	}

	/**
	 * The slugs of the given categories, which is what a product query wants.
	 *
	 * @param list<int> $ids Category term IDs.
	 *
	 * @return list<string>
	 */
	private function category_slugs( array $ids ): array {
		$slugs = array();

		foreach ( $ids as $id ) {
			$term = get_term( $id, 'product_cat' );

			if ( $term instanceof WP_Term ) {
				$slugs[] = $term->slug;
			}
		}

		return $slugs;
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
