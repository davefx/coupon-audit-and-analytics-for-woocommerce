<?php
/**
 * Putting a coupon's terms into words.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Admin;

use DFX\CouponAAW\Catalog\CatalogRepositoryInterface;
use DFX\CouponAAW\Catalog\ProductDetail;
use DFX\CouponAAW\Domain\Coupon\CouponScope;
use DFX\CouponAAW\Domain\Coupon\CouponTerms;
use DFX\CouponAAW\Domain\Profit\Money;

/**
 * Turns a coupon's terms and scope into sentences.
 *
 * The point is saying *what*, not *whether*. "Restricted" tells a shop owner
 * nothing they can act on; "only on Stoneware Mug (€12.00) and two products in
 * Kitchenware" tells them whether the restriction is the one they meant. Products
 * a customer could not buy are named as such, because a coupon restricted to a
 * product that is out of stock is doing nothing at all.
 */
final class CouponTermsFormatter {

	/**
	 * Constructor.
	 *
	 * @param CatalogRepositoryInterface $catalog Resolves product and category names.
	 */
	public function __construct( private readonly CatalogRepositoryInterface $catalog ) {}

	/**
	 * The discount itself, as a percentage or a sum.
	 *
	 * @param CouponTerms $terms The coupon's terms.
	 */
	public function amount( CouponTerms $terms ): string {
		if ( $terms->amount->is_percentage() ) {
			return sprintf(
				/* translators: %s: a percentage, already formatted. */
				esc_html__( '%s%%', 'coupon-audit-and-analytics-for-woocommerce' ),
				esc_html( number_format_i18n( (float) $terms->amount->percent, 2 ) )
			);
		}

		return null === $terms->amount->fixed ? '' : $this->money( $terms->amount->fixed );
	}

	/**
	 * The spend a basket must reach, or stay under.
	 *
	 * @param CouponTerms $terms The coupon's terms.
	 */
	public function required_spend( CouponTerms $terms ): string {
		$minimum = $terms->minimum_spend;
		$maximum = $terms->maximum_spend;

		if ( null !== $minimum && null !== $maximum ) {
			return sprintf(
				/* translators: 1: lowest basket total, 2: highest. */
				esc_html__( '%1$s to %2$s', 'coupon-audit-and-analytics-for-woocommerce' ),
				$this->money( $minimum ),
				$this->money( $maximum )
			);
		}

		if ( null !== $minimum ) {
			return sprintf(
				/* translators: %s: lowest basket total. */
				esc_html__( '%s or more', 'coupon-audit-and-analytics-for-woocommerce' ),
				$this->money( $minimum )
			);
		}

		if ( null !== $maximum ) {
			return sprintf(
				/* translators: %s: highest basket total. */
				esc_html__( 'up to %s', 'coupon-audit-and-analytics-for-woocommerce' ),
				$this->money( $maximum )
			);
		}

		return '<span class="dfxcaaw-unknown">' . esc_html__( 'any', 'coupon-audit-and-analytics-for-woocommerce' ) . '</span>';
	}

	/**
	 * How many redemptions are allowed, and to whom.
	 *
	 * @param int|null    $usage_count Redemptions so far.
	 * @param int|null    $usage_limit Total cap, if any.
	 * @param CouponTerms $terms       The coupon's terms.
	 */
	public function usage( ?int $usage_count, ?int $usage_limit, CouponTerms $terms ): string {
		$lines = array(
			esc_html(
				sprintf(
					/* translators: 1: redemptions so far, 2: the cap, or an infinity sign. */
					__( '%1$s of %2$s', 'coupon-audit-and-analytics-for-woocommerce' ),
					number_format_i18n( (int) $usage_count ),
					null === $usage_limit ? '∞' : number_format_i18n( $usage_limit )
				)
			),
		);

		if ( null !== $terms->usage_limit_per_user ) {
			$lines[] = esc_html(
				sprintf(
					/* translators: %d: redemptions allowed per customer. */
					_n(
						'%d per customer',
						'%d per customer',
						$terms->usage_limit_per_user,
						'coupon-audit-and-analytics-for-woocommerce'
					),
					$terms->usage_limit_per_user
				)
			);
		}

		if ( null !== $terms->limit_usage_to_items ) {
			$lines[] = esc_html(
				sprintf(
					/* translators: %d: items in one basket the coupon may apply to. */
					_n(
						'%d item per basket',
						'%d items per basket',
						$terms->limit_usage_to_items,
						'coupon-audit-and-analytics-for-woocommerce'
					),
					$terms->limit_usage_to_items
				)
			);
		}

		return implode( '<br />', $lines );
	}

	/**
	 * The flags that are either on or off.
	 *
	 * @param CouponTerms $terms The coupon's terms.
	 */
	public function flags( CouponTerms $terms ): string {
		$flags = array();

		if ( $terms->grants_free_shipping ) {
			$flags[] = esc_html__( 'Free shipping', 'coupon-audit-and-analytics-for-woocommerce' );
		}

		if ( $terms->is_individual_use ) {
			$flags[] = esc_html__( 'Individual use', 'coupon-audit-and-analytics-for-woocommerce' );
		}

		if ( $terms->has_email_restrictions() ) {
			$flags[] = esc_html(
				sprintf(
					/* translators: %s: comma-separated list of billing addresses. */
					__( 'Only for %s', 'coupon-audit-and-analytics-for-woocommerce' ),
					implode( ', ', $terms->email_restrictions )
				)
			);
		}

		if ( array() === $flags ) {
			return '<span class="dfxcaaw-finding-none">&mdash;</span>';
		}

		return '<span class="dfxcaaw-flag">' . implode( '</span> <span class="dfxcaaw-flag">', $flags ) . '</span>';
	}

	/**
	 * What the coupon actually applies to, named.
	 *
	 * @param CouponScope $scope The coupon's scope.
	 */
	public function scope( CouponScope $scope ): string {
		if ( $scope->is_universal() ) {
			return esc_html__( 'Everything', 'coupon-audit-and-analytics-for-woocommerce' );
		}

		$products   = $this->catalog->products(
			array_values( array_merge( $scope->included_products, $scope->excluded_products ) )
		);
		$categories = $this->catalog->category_names(
			array_values( array_merge( $scope->included_categories, $scope->excluded_categories ) )
		);

		$clauses = array();

		$this->add_clause( $clauses, __( 'Only', 'coupon-audit-and-analytics-for-woocommerce' ), $this->product_names( $scope->included_products, $products ) );
		$this->add_clause( $clauses, __( 'Only in', 'coupon-audit-and-analytics-for-woocommerce' ), $this->category_list( $scope->included_categories, $categories ) );
		$this->add_clause( $clauses, __( 'Never', 'coupon-audit-and-analytics-for-woocommerce' ), $this->product_names( $scope->excluded_products, $products ) );
		$this->add_clause( $clauses, __( 'Never in', 'coupon-audit-and-analytics-for-woocommerce' ), $this->category_list( $scope->excluded_categories, $categories ) );

		if ( $scope->excludes_sale_items ) {
			$clauses[] = esc_html__( 'Not on sale items', 'coupon-audit-and-analytics-for-woocommerce' );
		}

		return implode( '<br />', $clauses );
	}

	/**
	 * Add a clause where it has anything to say.
	 *
	 * @param list<string> $clauses The clauses so far, by reference.
	 * @param string       $lead    How the clause opens.
	 * @param string       $body    What it lists, or an empty string.
	 */
	private function add_clause( array &$clauses, string $lead, string $body ): void {
		if ( '' !== $body ) {
			$clauses[] = '<em>' . esc_html( $lead ) . '</em> ' . $body;
		}
	}

	/**
	 * Name products, marking any a customer could not buy.
	 *
	 * @param list<int>                 $ids      The products to name.
	 * @param array<int, ProductDetail> $products What the catalogue knows about them.
	 */
	private function product_names( array $ids, array $products ): string {
		$names = array();

		foreach ( $ids as $id ) {
			$product = $products[ $id ] ?? null;

			if ( null === $product ) {
				$names[] = sprintf(
					'<span class="dfxcaaw-gone">%s</span>',
					esc_html(
						sprintf(
							/* translators: %d: the ID of a product that no longer exists. */
							__( 'deleted product #%d', 'coupon-audit-and-analytics-for-woocommerce' ),
							$id
						)
					)
				);

				continue;
			}

			$label = esc_html( $product->name );

			if ( null !== $product->price ) {
				$label .= ' <span class="dfxcaaw-price">' . $this->money( $product->price ) . '</span>';
			}

			if ( ! $product->is_available ) {
				$label = sprintf(
					'<span class="dfxcaaw-gone" title="%1$s">%2$s</span>',
					esc_attr( (string) $product->unavailable ),
					$label
				);
			}

			$names[] = $label;
		}

		return implode( ', ', $names );
	}

	/**
	 * Name categories.
	 *
	 * @param list<int>          $ids   The categories to name.
	 * @param array<int, string> $names What the catalogue calls them.
	 */
	private function category_list( array $ids, array $names ): string {
		$labels = array();

		foreach ( $ids as $id ) {
			$labels[] = esc_html(
				$names[ $id ] ?? sprintf(
					/* translators: %d: the ID of a category that no longer exists. */
					__( 'deleted category #%d', 'coupon-audit-and-analytics-for-woocommerce' ),
					$id
				)
			);
		}

		return implode( ', ', $labels );
	}

	/**
	 * Render an amount held in minor units.
	 *
	 * @param Money $money The amount.
	 */
	private function money( Money $money ): string {
		$decimals = wc_get_price_decimals();

		return wp_kses_post(
			wc_price( $money->amount / ( 10 ** $decimals ), array( 'currency' => $money->currency ) )
		);
	}
}
