<?php
/**
 * Coupon scope.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Coupon;

/**
 * The real set of products a coupon affects, resolved from its restrictions
 * (§8.2): included and excluded products, included and excluded categories,
 * and the exclude-sale-items flag.
 *
 * A coupon with no restrictions has universal scope, which makes it overlap
 * with everything. That is correct, and usually the most useful finding the
 * inventory produces.
 */
final class CouponScope {

	/**
	 * Included product IDs, deduplicated and sorted.
	 *
	 * @var list<int>
	 */
	public readonly array $included_products;

	/**
	 * Excluded product IDs, deduplicated and sorted.
	 *
	 * @var list<int>
	 */
	public readonly array $excluded_products;

	/**
	 * Included category term IDs, deduplicated and sorted.
	 *
	 * @var list<int>
	 */
	public readonly array $included_categories;

	/**
	 * Excluded category term IDs, deduplicated and sorted.
	 *
	 * @var list<int>
	 */
	public readonly array $excluded_categories;

	/**
	 * Constructor.
	 *
	 * @param list<int> $included_products   Products the coupon is limited to.
	 * @param list<int> $excluded_products   Products the coupon never applies to.
	 * @param list<int> $included_categories Categories the coupon is limited to.
	 * @param list<int> $excluded_categories Categories the coupon never applies to.
	 * @param bool      $excludes_sale_items Whether products on sale are excluded.
	 */
	public function __construct(
		array $included_products = array(),
		array $excluded_products = array(),
		array $included_categories = array(),
		array $excluded_categories = array(),
		public readonly bool $excludes_sale_items = false
	) {
		$this->included_products   = self::normalise( $included_products );
		$this->excluded_products   = self::normalise( $excluded_products );
		$this->included_categories = self::normalise( $included_categories );
		$this->excluded_categories = self::normalise( $excluded_categories );
	}

	/**
	 * The scope of a coupon with no restrictions at all.
	 */
	public static function universal(): self {
		return new self();
	}

	/**
	 * Whether the coupon restricts nothing whatsoever.
	 */
	public function is_universal(): bool {
		return array() === $this->included_products
			&& array() === $this->excluded_products
			&& array() === $this->included_categories
			&& array() === $this->excluded_categories
			&& ! $this->excludes_sale_items;
	}

	/**
	 * Whether a given product falls inside this scope.
	 *
	 * Include lists are a union and act as a whitelist; every exclusion beats
	 * every inclusion, which is how WooCommerce itself resolves the two.
	 *
	 * @param ProductRef $product The product to test.
	 */
	public function contains( ProductRef $product ): bool {
		if ( ! $this->includes( $product ) ) {
			return false;
		}

		if ( in_array( $product->id, $this->excluded_products, true ) ) {
			return false;
		}

		if ( array() !== array_intersect( $product->category_ids, $this->excluded_categories ) ) {
			return false;
		}

		return ! ( $this->excludes_sale_items && $product->is_on_sale );
	}

	/**
	 * Whether two scopes could both apply to the same product.
	 *
	 * The name is deliberate. `false` means provably disjoint; `true` means
	 * possibly overlapping, because one case cannot be settled from the
	 * restrictions alone: a scope limited by product against a scope limited by
	 * category needs to know which categories that product belongs to. Milestone
	 * 5's indexed detector resolves that case against the real catalogue; here
	 * the honest answer is "maybe", and over-reporting is the safe direction for
	 * a check whose job is to raise suspicions.
	 *
	 * @param self $other The scope to compare against.
	 */
	public function may_intersect( self $other ): bool {
		if ( ! $this->restricts_positively() || ! $other->restricts_positively() ) {
			return true;
		}

		$shared_products = array_diff(
			array_intersect( $this->included_products, $other->included_products ),
			$this->excluded_products,
			$other->excluded_products
		);

		if ( array() !== $shared_products ) {
			return true;
		}

		$shared_categories = array_diff(
			array_intersect( $this->included_categories, $other->included_categories ),
			$this->excluded_categories,
			$other->excluded_categories
		);

		if ( array() !== $shared_categories ) {
			return true;
		}

		return $this->crosses( $other ) || $other->crosses( $this );
	}

	/**
	 * Whether two scopes restrict exactly the same things.
	 *
	 * @param self $other The scope to compare against.
	 */
	public function equals( self $other ): bool {
		return $this->included_products === $other->included_products
			&& $this->excluded_products === $other->excluded_products
			&& $this->included_categories === $other->included_categories
			&& $this->excluded_categories === $other->excluded_categories
			&& $this->excludes_sale_items === $other->excludes_sale_items;
	}

	/**
	 * Whether the product passes the include lists, before any exclusion.
	 *
	 * With no include lists at all the catalogue is the default set.
	 *
	 * @param ProductRef $product The product to test.
	 */
	private function includes( ProductRef $product ): bool {
		if ( ! $this->restricts_positively() ) {
			return true;
		}

		return in_array( $product->id, $this->included_products, true )
			|| array() !== array_intersect( $product->category_ids, $this->included_categories );
	}

	/**
	 * Whether the scope names the products it applies to, rather than starting
	 * from the whole catalogue.
	 */
	private function restricts_positively(): bool {
		return array() !== $this->included_products || array() !== $this->included_categories;
	}

	/**
	 * Whether this scope is limited by product while the other is limited by
	 * category. That pairing is the undecidable case described on
	 * `may_intersect()`.
	 *
	 * @param self $other The scope to compare against.
	 */
	private function crosses( self $other ): bool {
		return array() !== $this->included_products && array() !== $other->included_categories;
	}

	/**
	 * Deduplicate and sort a list of IDs, so that two scopes expressing the
	 * same restriction always compare equal.
	 *
	 * @param list<int> $ids The IDs to normalise.
	 *
	 * @return list<int>
	 */
	private static function normalise( array $ids ): array {
		$unique = array_values( array_unique( $ids ) );
		sort( $unique );

		return $unique;
	}
}
