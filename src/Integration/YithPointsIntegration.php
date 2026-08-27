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
		add_filter( 'dfxcaaw_coupon_rows_where', array( $this, 'exclude_generated_coupons' ), 10, 2 );
	}

	/**
	 * Leave the generated coupons out of every read.
	 *
	 * Excluded in the query rather than filtered afterwards, because the point
	 * is not to load them: a shop with fifty thousand reward coupons cannot
	 * afford to build fifty thousand objects and throw them away.
	 *
	 * This used to hook `dfxcaaw_coupon_query_args`, which reached the arguments
	 * `get_posts()` takes. The audit screen stopped reading that way when it was
	 * rewritten to read a page at a time, so the exclusion quietly stopped
	 * applying there — on precisely the shop this class exists for. The SQL
	 * filter reaches every read, which is why it replaced it; the old hook was
	 * removed in 0.9.0 once nothing was left that it could reliably reach.
	 *
	 * `NOT EXISTS` rather than `ID NOT IN ( ... )`: the subquery would return
	 * one row per reward coupon, which on the shop this is for is the fifty
	 * thousand rows the whole exercise is about not moving.
	 *
	 * The column is named without a table because the fragment goes into
	 * statements that alias the posts table `p` and into WP_Query's, which
	 * writes it out in full. Nothing else in scope has an `ID`, so an
	 * unqualified one is the coupon's.
	 *
	 * @param string $where The fragment so far.
	 * @param \wpdb  $wpdb  The database handle.
	 */
	public function exclude_generated_coupons( string $where, \wpdb $wpdb ): string {
		return $where . $wpdb->prepare(
			' AND NOT EXISTS (
				SELECT 1 FROM %i pm
				WHERE pm.post_id = ID AND pm.meta_key = %s
			)',
			$wpdb->postmeta,
			self::GENERATED_META
		);
	}
}
