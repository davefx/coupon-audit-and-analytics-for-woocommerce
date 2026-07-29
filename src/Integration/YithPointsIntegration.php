<?php
/**
 * YITH Points and Rewards support.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Integration;

/**
 * Keeps machine-generated reward coupons out of the audit.
 *
 * YITH Points and Rewards mints a coupon every time a customer redeems points.
 * A shop that has run the scheme for a year holds tens of thousands of them, and
 * they are not an inventory anybody audits: nobody chose their expiry, nobody
 * will act on their overlaps, and they would bury the coupons somebody did
 * choose. Worse, past a few hundred coupons overlap detection stops running at
 * all — so leaving them in would quietly disable a finding for every real
 * coupon in the shop.
 *
 * They are recognised by the `ywpar_coupon` meta the plugin stamps on each one
 * (verified against version 4.27.0), not by the `ywpar_discount` code prefix
 * that prior art matched on. The prefix is passed through a `ywpar_label_coupon`
 * filter before use, so any shop that has renamed it would defeat a prefix match
 * while the meta stays true.
 */
final class YithPointsIntegration implements IntegrationInterface {

	/**
	 * Stamped on every coupon the plugin generates.
	 */
	private const GENERATED_META = 'ywpar_coupon';

	/**
	 * Whether the plugin is running.
	 */
	public function is_active(): bool {
		return defined( 'YITH_YWPAR_VERSION' ) || class_exists( 'YITH_WC_Points_Rewards' );
	}

	/**
	 * Stable machine name.
	 */
	public function get_identifier(): string {
		return 'yith-points-rewards';
	}

	/**
	 * Human-readable name.
	 */
	public function get_label(): string {
		return __( 'YITH WooCommerce Points and Rewards', 'coupon-audit-and-analytics-for-woocommerce' );
	}

	/**
	 * Attach the hooks.
	 */
	public function register(): void {
		add_filter( 'dfxcaaw_coupon_query_args', array( $this, 'exclude_generated_coupons' ) );
	}

	/**
	 * Leave the generated coupons out of the query.
	 *
	 * Excluded in the query rather than filtered afterwards, because the point
	 * is not to load them: a shop with fifty thousand reward coupons cannot
	 * afford to build fifty thousand objects and throw them away.
	 *
	 * @param array<string, mixed> $args Arguments for get_posts().
	 *
	 * @return array<string, mixed>
	 */
	public function exclude_generated_coupons( array $args ): array {
		$meta_query = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();

		$meta_query[] = array(
			'key'     => self::GENERATED_META,
			'compare' => 'NOT EXISTS',
		);

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$args['meta_query'] = $meta_query;

		return $args;
	}
}
