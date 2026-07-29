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
use wpdb;
use WP_Term;

/**
 * Reads product and category detail from WooCommerce.
 *
 * The cheapest-price query is the reason this class exists rather than a loop.
 * Asking "is anything this coupon reaches cheaper than the discount" by loading
 * every product in every referenced category — which is how the prior art did
 * it — costs a full product object per row, and a shop with a thousand-product
 * category pays that on every page load. WooCommerce maintains
 * `wc_product_meta_lookup` with an indexed `min_price` for exactly this, so the
 * whole question is one aggregate query.
 */
final class WcCatalogRepository implements CatalogRepositoryInterface {

	/**
	 * Constructor.
	 *
	 * @param wpdb   $wpdb     WordPress database handle.
	 * @param string $currency The store's currency.
	 * @param int    $decimals Places in the currency's minor unit.
	 */
	public function __construct(
		private readonly wpdb $wpdb,
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
		$wpdb = $this->wpdb;

		$lookup     = $wpdb->prefix . 'wc_product_meta_lookup';
		$conditions = array();

		if ( array() !== $scope->included_products ) {
			$conditions[] = 'l.product_id IN ( ' . $this->id_list( $scope->included_products ) . ' )';
		}

		if ( array() !== $scope->included_categories ) {
			$conditions[] = sprintf(
				'l.product_id IN ( SELECT r.object_id FROM %1$s r
					INNER JOIN %2$s t ON t.term_taxonomy_id = r.term_taxonomy_id
					WHERE t.taxonomy = \'product_cat\' AND t.term_id IN ( %3$s ) )',
				$wpdb->term_relationships,
				$wpdb->term_taxonomy,
				$this->id_list( $scope->included_categories )
			);
		}

		// An unrestricted coupon reaches the whole catalogue, so the cheapest
		// thing in the shop is the cheapest thing it can be applied to.
		$where = array() === $conditions ? '1 = 1' : '( ' . implode( ' OR ', $conditions ) . ' )';

		if ( array() !== $scope->excluded_products ) {
			$where .= ' AND l.product_id NOT IN ( ' . $this->id_list( $scope->excluded_products ) . ' )';
		}

		/*
		 * Assembled rather than prepared, and deliberately so. The only values
		 * that vary are ID lists, and `id_list()` puts every one of them through
		 * absint(), so nothing but digits can reach the query — a variable-length
		 * IN list cannot be expressed as the literal string prepare() requires,
		 * and pretending otherwise buys nothing here. Table names come from
		 * $wpdb's own properties and the taxonomy is spelled out.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cheapest = $wpdb->get_var(
			"SELECT MIN( l.min_price ) FROM {$lookup} l WHERE {$where} AND l.min_price IS NOT NULL"
		);
		// phpcs:enable

		if ( ! is_numeric( $cheapest ) ) {
			return null;
		}

		return Money::from_decimal( (float) $cheapest, $this->currency, $this->decimals );
	}

	/**
	 * A comma-separated list of IDs, each forced to a non-negative integer.
	 *
	 * This is what makes assembling the query above safe: absint() leaves
	 * nothing but digits, so there is no value through which anything could be
	 * injected.
	 *
	 * @param list<int> $ids The IDs to list.
	 */
	private function id_list( array $ids ): string {
		return implode( ', ', array_map( 'absint', $ids ) );
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
