<?php
/**
 * WordPress coupon repository.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Repository;

use DateTimeImmutable;
use DateTimeZone;
use DFX\CouponAAW\Domain\Coupon\CouponId;
use DFX\CouponAAW\Domain\Coupon\CouponScope;
use DFX\CouponAAW\Domain\Coupon\CouponSnapshot;
use DFX\CouponAAW\Domain\Coupon\CouponTerms;
use DFX\CouponAAW\Domain\Coupon\DiscountAmount;
use DFX\CouponAAW\Domain\Profit\Money;
use WC_Coupon;
use WP_Post;
use wpdb;

/**
 * Reads coupons from the `shop_coupon` post type and its meta.
 *
 * Values are pulled through `WC_Coupon` rather than by reading meta keys
 * directly. WooCommerce still falls back from `date_expires` to a legacy
 * `expiry_date`, still accepts restriction lists stored either as arrays or as
 * comma-separated strings, and is free to change both again. Going through its
 * API means those decisions stay WooCommerce's problem, which is precisely what
 * §5 puts a repository here for.
 */
final class WpCouponRepository implements CouponRepositoryInterface {

	/**
	 * The post type coupons live in.
	 */
	private const POST_TYPE = 'shop_coupon';

	/**
	 * Post statuses that mean the store intends the coupon to be live.
	 *
	 * `future` is included deliberately. WooCommerce has no start date of its
	 * own, so a coupon scheduled to publish later is the only thing core offers;
	 * treating it as unpublished would resolve it to inactive and the SCHEDULED
	 * status could never occur.
	 */
	private const LIVE_STATUSES = array( 'publish', 'future' );

	/**
	 * Whether the analytics lookup table has been checked for this request.
	 *
	 * @var bool|null
	 */
	private ?bool $lookup_table_exists = null;

	/**
	 * Constructor.
	 *
	 * @param wpdb         $wpdb     WordPress database handle.
	 * @param DateTimeZone $timezone The site's timezone.
	 * @param int          $decimals Places in the currency's minor unit.
	 */
	public function __construct(
		private readonly wpdb $wpdb,
		private readonly DateTimeZone $timezone,
		private readonly int $decimals = 2
	) {}

	/**
	 * Find one coupon.
	 *
	 * @param CouponId $id The coupon to load.
	 */
	public function find( CouponId $id ): ?CouponSnapshot {
		$post = get_post( $id->value );

		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return $this->to_snapshot( $post, $this->last_used( array( $id->value ) ) );
	}

	/**
	 * Every coupon in the store.
	 *
	 * @return list<CouponSnapshot>
	 */
	public function all(): array {
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => $this->listed_statuses(),
				'numberposts'      => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);

		if ( array() === $posts ) {
			return array();
		}

		$ids = array_values( array_map( static fn ( WP_Post $post ): int => $post->ID, $posts ) );

		// One query for every coupon's last use, rather than one per coupon.
		$last_used = $this->last_used( $ids );

		return array_values(
			array_map(
				fn ( WP_Post $post ): CouponSnapshot => $this->to_snapshot( $post, $last_used ),
				$posts
			)
		);
	}

	/**
	 * How many coupons exist.
	 */
	public function count(): int {
		$counts = (array) wp_count_posts( self::POST_TYPE );
		$total  = 0;

		foreach ( $this->listed_statuses() as $status ) {
			$total += (int) ( $counts[ $status ] ?? 0 );
		}

		return $total;
	}

	/**
	 * The post statuses this repository considers part of the inventory.
	 *
	 * Counting and listing are computed different ways — one from WordPress's
	 * per-status tallies, the other from a query — so both are driven from this
	 * one list. Without it a trashed coupon lands in the count but not the
	 * listing, and a screen promises twelve coupons while showing eleven.
	 *
	 * @return list<string>
	 */
	private function listed_statuses(): array {
		return array_values( get_post_stati( array( 'exclude_from_search' => false ) ) );
	}

	/**
	 * Build a snapshot from a coupon post.
	 *
	 * @param WP_Post            $post      The coupon post.
	 * @param array<int, string> $last_used Last-use timestamps keyed by coupon ID.
	 */
	private function to_snapshot( WP_Post $post, array $last_used ): CouponSnapshot {
		$coupon = new WC_Coupon( $post->ID );

		$usage_limit = $coupon->get_usage_limit();

		return new CouponSnapshot(
			new CouponId( $post->ID ),
			$coupon->get_code(),
			in_array( $post->post_status, self::LIVE_STATUSES, true ),
			$this->post_date( $post, 'date_gmt' ),
			'future' === $post->post_status ? $this->post_date( $post, 'date' ) : null,
			$this->expiry_of( $coupon ),
			// WooCommerce stores "unlimited" as zero; the domain says null.
			$usage_limit > 0 ? $usage_limit : null,
			$coupon->get_usage_count(),
			isset( $last_used[ $post->ID ] ) ? $this->local_datetime( $last_used[ $post->ID ] ) : null,
			$this->scope_of( $coupon ),
			$this->is_auto_applied( $coupon ),
			$this->terms_of( $coupon )
		);
	}

	/**
	 * The coupon's commercial terms.
	 *
	 * @param WC_Coupon $coupon The coupon.
	 */
	private function terms_of( WC_Coupon $coupon ): CouponTerms {
		return new CouponTerms(
			$this->discount_of( $coupon ),
			$this->optional_money( $coupon->get_minimum_amount() ),
			$this->optional_money( $coupon->get_maximum_amount() ),
			(bool) $coupon->get_free_shipping(),
			(bool) $coupon->get_individual_use(),
			$this->optional_count( $coupon->get_usage_limit_per_user() ),
			$this->optional_count( $coupon->get_limit_usage_to_x_items() ),
			array_values( array_map( 'strval', $coupon->get_email_restrictions() ) )
		);
	}

	/**
	 * What the coupon takes off.
	 *
	 * WooCommerce's discount types are extensible, so a type is treated as a
	 * percentage when it says so and as a fixed sum otherwise. Guessing the
	 * other way round would let a custom percentage type be checked as though it
	 * could exceed the basket, and report a fault that cannot happen.
	 *
	 * @param WC_Coupon $coupon The coupon.
	 */
	private function discount_of( WC_Coupon $coupon ): DiscountAmount {
		$type   = (string) $coupon->get_discount_type();
		$amount = (float) $coupon->get_amount();

		if ( str_contains( $type, 'percent' ) ) {
			return DiscountAmount::percentage( $amount, $type );
		}

		return DiscountAmount::fixed(
			Money::from_decimal( $amount, $this->currency(), $this->decimals ),
			$type
		);
	}

	/**
	 * A monetary threshold, or null where WooCommerce stores "unset" as empty.
	 *
	 * @param string $amount The stored amount.
	 */
	private function optional_money( string $amount ): ?Money {
		if ( '' === trim( $amount ) || ! is_numeric( $amount ) ) {
			return null;
		}

		return Money::from_decimal( (float) $amount, $this->currency(), $this->decimals );
	}

	/**
	 * A cap, or null where WooCommerce stores "no cap" as zero or empty.
	 *
	 * @param mixed $value The stored value.
	 */
	private function optional_count( mixed $value ): ?int {
		$count = (int) $value;

		return $count > 0 ? $count : null;
	}

	/**
	 * The store's currency.
	 */
	private function currency(): string {
		return get_woocommerce_currency();
	}

	/**
	 * Whether the coupon applies without the customer entering a code.
	 *
	 * WooCommerce has no such concept — verified against 10.7, which mentions
	 * auto-apply nowhere in either WC_Coupon or its data store — so the answer is
	 * always no unless a plugin that adds auto-apply says otherwise. §8.3 grades
	 * an overlap between two auto-applied coupons as its most serious finding,
	 * and without this hook that grade could never occur at all.
	 *
	 * @param WC_Coupon $coupon The coupon.
	 */
	private function is_auto_applied( WC_Coupon $coupon ): bool {
		/**
		 * Filters whether a coupon is applied automatically at checkout.
		 *
		 * WooCommerce has no auto-apply of its own, so this is the seam through
		 * which a plugin that adds one tells the audit about it.
		 *
		 * @since 0.1.0
		 *
		 * @param bool      $is_auto_applied Whether the coupon applies without being entered.
		 * @param int       $coupon_id       The coupon's post ID.
		 * @param WC_Coupon $coupon          The coupon itself.
		 */
		return (bool) apply_filters(
			'dfxcaaw_coupon_is_auto_applied',
			false,
			$coupon->get_id(),
			$coupon
		);
	}

	/**
	 * The coupon's expiry as an instant in the site's timezone.
	 *
	 * @param WC_Coupon $coupon The coupon.
	 */
	private function expiry_of( WC_Coupon $coupon ): ?DateTimeImmutable {
		$expires = $coupon->get_date_expires();

		if ( null === $expires ) {
			return null;
		}

		return $this->local_datetime( $expires->date( 'Y-m-d H:i:s' ) );
	}

	/**
	 * The coupon's restrictions, resolved into a scope.
	 *
	 * @param WC_Coupon $coupon The coupon.
	 */
	private function scope_of( WC_Coupon $coupon ): CouponScope {
		return new CouponScope(
			included_products: self::ids( $coupon->get_product_ids() ),
			excluded_products: self::ids( $coupon->get_excluded_product_ids() ),
			included_categories: self::ids( $coupon->get_product_categories() ),
			excluded_categories: self::ids( $coupon->get_excluded_product_categories() ),
			excludes_sale_items: (bool) $coupon->get_exclude_sale_items()
		);
	}

	/**
	 * The most recent redemption of each of the given coupons.
	 *
	 * Read from the WooCommerce Analytics coupon lookup table, whose
	 * `date_created` column WooCommerce writes in site-local time rather than
	 * UTC. An empty result is not an error: a store with Analytics disabled has
	 * no such table, and the audit is still useful without it.
	 *
	 * @param list<int> $coupon_ids The coupons to look up.
	 *
	 * @return array<int, string>
	 */
	private function last_used( array $coupon_ids ): array {
		if ( array() === $coupon_ids || ! $this->has_lookup_table() ) {
			return array();
		}

		// Aliased so that WPCS can recognise the prepare() call; the sniff only
		// follows a variable literally named $wpdb.
		$wpdb = $this->wpdb;

		// Interpolating one %d per ID is the only way to build an IN list, and
		// they are placeholders rather than values. The table name goes through
		// the %i identifier placeholder, so nothing is concatenated raw.
		$placeholders = implode( ', ', array_fill( 0, count( $coupon_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT coupon_id, MAX(date_created) AS last_used FROM %i WHERE coupon_id IN ({$placeholders}) GROUP BY coupon_id",
				array_merge( array( $this->lookup_table() ), $coupon_ids )
			)
		);

		$last_used = array();

		foreach ( (array) $rows as $row ) {
			$last_used[ (int) $row->coupon_id ] = (string) $row->last_used;
		}

		return $last_used;
	}

	/**
	 * Whether the analytics lookup table is present, checked once per request.
	 */
	private function has_lookup_table(): bool {
		if ( null === $this->lookup_table_exists ) {
			$table = $this->lookup_table();
			$wpdb  = $this->wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			$this->lookup_table_exists = $table === $found;
		}

		return $this->lookup_table_exists;
	}

	/**
	 * The analytics coupon lookup table name.
	 */
	private function lookup_table(): string {
		return $this->wpdb->prefix . 'wc_order_coupon_lookup';
	}

	/**
	 * Read a post date as an instant in the site's timezone.
	 *
	 * @param WP_Post $post  The post.
	 * @param string  $field Either `date` for local or `date_gmt` for UTC.
	 */
	private function post_date( WP_Post $post, string $field ): DateTimeImmutable {
		$date = get_post_datetime( $post, 'date', 'date_gmt' === $field ? 'gmt' : 'local' );

		if ( false === $date ) {
			// A post always has a date; this satisfies the type rather than
			// describing a case that can happen.
			return new DateTimeImmutable( 'now', $this->timezone );
		}

		return $date->setTimezone( $this->timezone );
	}

	/**
	 * Interpret a site-local datetime string as an instant.
	 *
	 * @param string $value A `Y-m-d H:i:s` string in the site's timezone.
	 */
	private function local_datetime( string $value ): DateTimeImmutable {
		return new DateTimeImmutable( $value, $this->timezone );
	}

	/**
	 * Coerce a WooCommerce restriction list into a list of IDs.
	 *
	 * @param array<int|string> $values Whatever WooCommerce returned.
	 *
	 * @return list<int>
	 */
	private static function ids( array $values ): array {
		return array_values(
			array_filter(
				array_map( 'intval', $values ),
				static fn ( int $id ): bool => $id > 0
			)
		);
	}
}
