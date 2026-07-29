<?php
/**
 * WooCommerce Extended Coupon Features support.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Integration;

use DFX\CouponAAW\Service\InventoryEntry;
use WC_Coupon;

/**
 * Teaches the audit about WooCommerce Extended Coupon Features.
 *
 * Every key below was read out of that plugin's own source (version 3.4.1)
 * rather than taken from documentation or from another plugin's reading of it.
 * That mattered: an existing integration elsewhere reads
 * `_wjecf_ignore_free_products`, which the plugin does not define at all, and
 * reads `_wjecf_products_and` twice — so its "categories AND/OR" label always
 * mirrors the products setting. `_wjecf_categories_and` is a real key and is
 * used here.
 *
 * The important one is `_wjecf_is_auto_coupon`. WooCommerce has no notion of a
 * coupon that applies without being typed in, and §8.3 grades an overlap
 * between two such coupons as its most serious finding. Without a plugin that
 * provides auto-application, that grade can never occur; with this one, it can.
 */
final class WjecfIntegration implements IntegrationInterface {

	/**
	 * Marks a coupon that applies without the customer entering it.
	 */
	private const AUTO_COUPON = '_wjecf_is_auto_coupon';

	/**
	 * Whether the plugin is running.
	 */
	public function is_active(): bool {
		return class_exists( 'WJECF_Controller' ) || defined( 'WJECF_VERSION' );
	}

	/**
	 * Stable machine name.
	 */
	public function get_identifier(): string {
		return 'wjecf';
	}

	/**
	 * Human-readable name.
	 */
	public function get_label(): string {
		return __( 'WooCommerce Extended Coupon Features', 'coupon-audit-and-analytics-for-woocommerce' );
	}

	/**
	 * Attach the hooks.
	 */
	public function register(): void {
		add_filter( 'dfxcaaw_coupon_is_auto_applied', array( $this, 'is_auto_applied' ), 10, 3 );
		add_filter( 'dfxcaaw_inventory_columns', array( $this, 'add_column' ) );
		add_filter( 'dfxcaaw_inventory_cell', array( $this, 'render_cell' ), 10, 3 );
	}

	/**
	 * Whether this coupon applies without being entered.
	 *
	 * @param bool      $is_auto_applied What was concluded so far.
	 * @param int       $coupon_id       The coupon's post ID.
	 * @param WC_Coupon $coupon          The coupon itself.
	 */
	public function is_auto_applied( bool $is_auto_applied, int $coupon_id, WC_Coupon $coupon ): bool {
		return $is_auto_applied || 'yes' === $coupon->get_meta( self::AUTO_COUPON );
	}

	/**
	 * Add a column for the conditions this plugin adds.
	 *
	 * @param array<string, string> $columns The columns so far.
	 *
	 * @return array<string, string>
	 */
	public function add_column( array $columns ): array {
		$columns['wjecf'] = __( 'Extended conditions', 'coupon-audit-and-analytics-for-woocommerce' );

		return $columns;
	}

	/**
	 * Describe those conditions for one coupon.
	 *
	 * @param string         $content What the cell holds so far.
	 * @param string         $column  Which column is being rendered.
	 * @param InventoryEntry $entry   The coupon and its findings.
	 */
	public function render_cell( string $content, string $column, InventoryEntry $entry ): string {
		if ( 'wjecf' !== $column ) {
			return $content;
		}

		$coupon = new WC_Coupon( $entry->coupon->id->value );
		$notes  = array();

		if ( 'yes' === $coupon->get_meta( self::AUTO_COUPON ) ) {
			$notes[] = __( 'Applied automatically', 'coupon-audit-and-analytics-for-woocommerce' );
		}

		if ( 'yes' === $coupon->get_meta( '_wjecf_first_purchase_only' ) ) {
			$notes[] = __( 'First purchase only', 'coupon-audit-and-analytics-for-woocommerce' );
		}

		$notes[] = $this->matching_rule( $coupon, '_wjecf_products_and', __( 'Needs all listed products', 'coupon-audit-and-analytics-for-woocommerce' ), __( 'Needs any listed product', 'coupon-audit-and-analytics-for-woocommerce' ) );
		$notes[] = $this->matching_rule( $coupon, '_wjecf_categories_and', __( 'Needs all listed categories', 'coupon-audit-and-analytics-for-woocommerce' ), __( 'Needs any listed category', 'coupon-audit-and-analytics-for-woocommerce' ) );

		$notes[] = $this->range(
			$coupon,
			'_wjecf_min_matching_product_qty',
			'_wjecf_max_matching_product_qty',
			/* translators: %s: a quantity or range of quantities. */
			__( 'Matching items: %s', 'coupon-audit-and-analytics-for-woocommerce' )
		);

		$notes[] = $this->range(
			$coupon,
			'_wjecf_min_matching_product_subtotal',
			'_wjecf_max_matching_product_subtotal',
			/* translators: %s: an amount or range of amounts. */
			__( 'Matching subtotal: %s', 'coupon-audit-and-analytics-for-woocommerce' )
		);

		$notes[] = $this->listed(
			$coupon,
			'_wjecf_customer_roles',
			/* translators: %s: comma-separated list of customer roles. */
			__( 'Only roles: %s', 'coupon-audit-and-analytics-for-woocommerce' )
		);

		$notes[] = $this->listed(
			$coupon,
			'_wjecf_excluded_customer_roles',
			/* translators: %s: comma-separated list of customer roles. */
			__( 'Not roles: %s', 'coupon-audit-and-analytics-for-woocommerce' )
		);

		$notes[] = $this->listed(
			$coupon,
			'_wjecf_shipping_methods',
			/* translators: %s: comma-separated list of shipping methods. */
			__( 'Shipping: %s', 'coupon-audit-and-analytics-for-woocommerce' )
		);

		$notes[] = $this->listed(
			$coupon,
			'_wjecf_payment_methods',
			/* translators: %s: comma-separated list of payment methods. */
			__( 'Payment: %s', 'coupon-audit-and-analytics-for-woocommerce' )
		);

		$notes = array_values( array_filter( $notes ) );

		if ( array() === $notes ) {
			return '<span class="dfxcaaw-finding-none">&mdash;</span>';
		}

		return '<span class="dfxcaaw-flag">' . implode( '</span> <span class="dfxcaaw-flag">', array_map( 'esc_html', $notes ) ) . '</span>';
	}

	/**
	 * Whether a list must all match or any of it may.
	 *
	 * Only said where the coupon actually restricts by that thing, since "needs
	 * any listed product" against no listed products is noise.
	 *
	 * @param WC_Coupon $coupon The coupon.
	 * @param string    $key    The meta key holding the AND/OR choice.
	 * @param string    $all    Wording when all must match.
	 * @param string    $any    Wording when any may.
	 */
	private function matching_rule( WC_Coupon $coupon, string $key, string $all, string $any ): string {
		$restricts = '_wjecf_products_and' === $key
			? array() !== $coupon->get_product_ids()
			: array() !== $coupon->get_product_categories();

		if ( ! $restricts ) {
			return '';
		}

		return 'yes' === $coupon->get_meta( $key ) ? $all : $any;
	}

	/**
	 * A minimum, a maximum, or both, where either is set.
	 *
	 * @param WC_Coupon $coupon   The coupon.
	 * @param string    $min_key  Meta key holding the minimum.
	 * @param string    $max_key  Meta key holding the maximum.
	 * @param string    $template How to phrase the result.
	 */
	private function range( WC_Coupon $coupon, string $min_key, string $max_key, string $template ): string {
		$min = (float) $coupon->get_meta( $min_key );
		$max = (float) $coupon->get_meta( $max_key );

		if ( $min <= 0 && $max <= 0 ) {
			return '';
		}

		if ( $min > 0 && $max > 0 ) {
			$range = sprintf( '%1$s–%2$s', number_format_i18n( $min ), number_format_i18n( $max ) );
		} elseif ( $min > 0 ) {
			$range = sprintf( '≥ %s', number_format_i18n( $min ) );
		} else {
			$range = sprintf( '≤ %s', number_format_i18n( $max ) );
		}

		return sprintf( $template, $range );
	}

	/**
	 * A meta value that holds a list, phrased where it holds anything.
	 *
	 * @param WC_Coupon $coupon   The coupon.
	 * @param string    $key      The meta key.
	 * @param string    $template How to phrase the result.
	 */
	private function listed( WC_Coupon $coupon, string $key, string $template ): string {
		$value = $coupon->get_meta( $key );

		if ( is_string( $value ) ) {
			$value = '' === trim( $value ) ? array() : array_map( 'trim', explode( ',', $value ) );
		}

		if ( ! is_array( $value ) || array() === $value ) {
			return '';
		}

		return sprintf( $template, implode( ', ', array_map( 'strval', $value ) ) );
	}
}
