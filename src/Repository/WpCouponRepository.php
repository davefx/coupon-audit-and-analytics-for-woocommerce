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
use WC_Data;
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
	 * The object-cache group WooCommerce keeps coupon meta in.
	 *
	 * `WC_Coupon::$cache_group`, which is protected, so it is repeated rather
	 * than read. Should it ever change upstream, entries written here are simply
	 * never read and coupons load as they did before.
	 */
	private const CACHE_GROUP = 'coupons';

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
		/*
		 * Only real posts survive. The query arguments are filterable, so a
		 * plugin could set `fields` and hand back bare IDs; dropping anything
		 * that is not a post keeps that from becoming a fatal error deep in the
		 * mapping.
		 */
		$posts = array_values(
			array_filter(
				get_posts( $this->query_args() ),
				static fn ( $post ): bool => $post instanceof WP_Post
			)
		);

		if ( array() === $posts ) {
			return array();
		}

		$ids = array_values( array_map( static fn ( WP_Post $post ): int => $post->ID, $posts ) );

		// One query for every coupon's last use, rather than one per coupon.
		$last_used = $this->last_used( $ids );

		$this->prime_meta_cache( $ids );

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
		$args           = $this->query_args();
		$args['fields'] = 'ids';

		return count( get_posts( $args ) );
	}

	/**
	 * The query behind the inventory.
	 *
	 * Filterable because some plugins mint a coupon per customer. A points-and-
	 * rewards shop can hold tens of thousands of generated codes, and they are
	 * not an inventory anybody audits: they would bury the real coupons, and
	 * past a few hundred they would silently switch overlap detection off for
	 * everything else. Excluding them belongs in the query rather than in a
	 * filter applied afterwards, or the shop pays to load them first.
	 *
	 * @return array<string, mixed>
	 */
	private function query_args(): array {
		$args = array(
			'post_type'        => self::POST_TYPE,
			'post_status'      => $this->listed_statuses(),
			'numberposts'      => -1,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'suppress_filters' => false,
		);

		/**
		 * Filters the query that lists coupons for the audit.
		 *
		 * Adding a meta query here is how an integration keeps machine-generated
		 * coupons out of the inventory.
		 *
		 * @since 0.2.0
		 *
		 * @param array<string, mixed> $args Arguments for get_posts().
		 */
		return apply_filters( 'dfxcaaw_coupon_query_args', $args );
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
	 * Load every coupon's meta in one query, into WooCommerce's own cache.
	 *
	 * `WC_Coupon` reads its meta through `WC_Data_Store_WP::read_meta()`, which
	 * issues one query per coupon. On a shop with five hundred coupons that is
	 * five hundred round trips to build one screen, and it was the largest single
	 * cost in the page. `WC_Data` checks its cache before reading, so filling
	 * that cache up front turns all of them into none.
	 *
	 * The rows are stored in the shape `read_meta()` returns, real `meta_id`s and
	 * all. That detail is not cosmetic: `WC_Data` uses those IDs to tell existing
	 * meta from new, and meta cached without them would be re-inserted the next
	 * time anything saved the coupon — with a persistent object cache, long after
	 * this request. WordPress's own `update_meta_cache()` would have been the
	 * tidier source but it discards the IDs, so the query is made here instead.
	 * Internal keys are not filtered out; WooCommerce re-filters on the way in.
	 *
	 * If any of this ever stops matching WooCommerce, the cache simply goes unread
	 * and the coupons load the slow way, which is where they started.
	 *
	 * @param list<int> $ids The coupons about to be built.
	 */
	private function prime_meta_cache( array $ids ): void {
		if ( array() === $ids || ! class_exists( 'WC_Data' ) ) {
			return;
		}

		$keys = array();

		foreach ( $ids as $id ) {
			$key = WC_Data::generate_meta_cache_key( $id, self::CACHE_GROUP );

			// Anything already cached is left alone: it may hold changes this
			// request made that the database has not been asked for since.
			if ( ! is_array( wp_cache_get( $key, self::CACHE_GROUP ) ) ) {
				$keys[ $id ] = $key;
			}
		}

		if ( array() === $keys ) {
			return;
		}

		// Aliased so that WPCS can recognise the prepare() call; the sniff only
		// follows a variable literally named $wpdb.
		$wpdb = $this->wpdb;

		// One %d per ID, as in last_used(): placeholders rather than values, and
		// the table name goes through the %i identifier placeholder.
		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This *is* the caching: the results are written straight to the object cache below.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT post_id, meta_id, meta_key, meta_value FROM %i WHERE post_id IN ({$placeholders}) ORDER BY meta_id",
				array_merge( array( $wpdb->postmeta ), array_keys( $keys ) )
			)
		);

		// Coupons with no meta at all still get an entry, or each one costs the
		// query this method exists to avoid.
		$meta = array_fill_keys( array_keys( $keys ), array() );

		foreach ( (array) $rows as $row ) {
			$id = (int) $row->post_id;

			if ( ! isset( $meta[ $id ] ) ) {
				continue;
			}

			// phpcs:disable WordPress.DB.SlowDBQuery -- Not query arguments: these are the row's own field names, in the shape WooCommerce reads back.
			$meta[ $id ][] = (object) array(
				'meta_id'    => $row->meta_id,
				'meta_key'   => $row->meta_key,
				'meta_value' => $row->meta_value,
			);
			// phpcs:enable WordPress.DB.SlowDBQuery
		}

		foreach ( $meta as $id => $rows_for_coupon ) {
			wp_cache_set( $keys[ $id ], $rows_for_coupon, self::CACHE_GROUP );
		}
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

		$money = Money::from_decimal( (float) $amount, $this->currency(), $this->decimals );

		/*
		 * Zero means unset. WooCommerce stores an absent spend limit as an empty
		 * string but hands it back as "0", so guarding against emptiness alone is
		 * not enough — and it is not enough in a way that is easy to miss, because
		 * the meta really is empty when you look at it.
		 *
		 * Reading zero as a limit is also wrong on its own terms: no basket totals
		 * less than nothing, so a minimum of zero admits everything, and a maximum
		 * of zero would admit nothing at all. Either way it is not a limit, and
		 * rendering it as one puts "€0.00 to €0.00" against almost every coupon in
		 * a shop.
		 */
		return $money->is_zero() ? null : $money;
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
