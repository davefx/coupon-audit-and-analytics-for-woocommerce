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
use DFX\CouponAAW\Domain\Coupon\CouponProjection;
use DFX\CouponAAW\Domain\Coupon\CouponSnapshot;
use DFX\CouponAAW\Domain\Coupon\CouponTerms;
use DFX\CouponAAW\Domain\Coupon\DiscountAmount;
use DFX\CouponAAW\Domain\Profit\Money;
use WC_Coupon;
use WC_Data;
use WP_Post;
use WP_Query;
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
	 * Whether a coupon in this state can be reached by anybody typing its code.
	 *
	 * WooCommerce looks a coupon up with `post_status = 'publish'` hard-coded,
	 * so nothing else is reachable. `future` is the exception worth keeping:
	 * it is not redeemable today and becomes so without anybody doing anything,
	 * when WordPress publishes it on its date. That is "not yet" rather than
	 * "not ever", which is why this is written against the statuses the audit
	 * already calls live rather than against a list of statuses to exclude —
	 * a status this plugin has never seen is then judged by the same rule.
	 *
	 * Unreachable is not the same as harmless. Loading a coupon by ID does not
	 * check the status, and WooCommerce's validation rejects only `trash`, so
	 * anything applying coupons without them being typed will discount an order
	 * with one. They belong in the audit exactly when something does.
	 *
	 * @param string $status The post status.
	 */
	private static function is_reachable( string $status ): bool {
		return in_array( $status, self::LIVE_STATUSES, true );
	}

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
				$this->excluding( fn (): array => get_posts( $this->query_args() ) ),
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

		// The same rule project() applies, and the snapshot already carries both
		// halves of it — `is_published` is exactly "in a live status" — so
		// listing and projecting agree about what the shop holds.
		return array_values(
			array_filter(
				array_map(
					fn ( WP_Post $post ): CouponSnapshot => $this->to_snapshot( $post, $last_used ),
					$posts
				),
				static fn ( CouponSnapshot $coupon ): bool => $coupon->is_published || $coupon->is_auto_applied
			)
		);
	}

	/**
	 * Build only the coupons named, in the order they were named.
	 *
	 * The audit screen shows twenty rows. It used to reach them by building
	 * every coupon in the shop and slicing, which at the customer's twenty-six
	 * thousand is most of a gigabyte to display four hundred bytes of table.
	 * Deciding *which* twenty is now done on projections, and this builds those.
	 *
	 * The order is the caller's, not the database's. Whoever chose these already
	 * sorted them, and re-sorting here would silently override that.
	 *
	 * @param list<CouponId> $ids The coupons wanted.
	 *
	 * @return list<CouponSnapshot>
	 */
	public function some( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		$wanted = array_values( array_map( static fn ( CouponId $id ): int => $id->value, $ids ) );

		$posts = array_filter(
			get_posts(
				array(
					'post_type'        => self::POST_TYPE,
					'post_status'      => $this->listed_statuses(),
					'post__in'         => $wanted,
					'numberposts'      => count( $wanted ),
					'orderby'          => 'post__in',
					'suppress_filters' => false,
				)
			),
			static fn ( $post ): bool => $post instanceof WP_Post
		);

		if ( array() === $posts ) {
			return array();
		}

		$found = array_values( array_map( static fn ( WP_Post $post ): int => $post->ID, $posts ) );

		$last_used = $this->last_used( $found );

		$this->prime_meta_cache( $found );

		return array_values(
			array_map(
				fn ( WP_Post $post ): CouponSnapshot => $this->to_snapshot( $post, $last_used ),
				$posts
			)
		);
	}

	/**
	 * The codes of the coupons named, keyed by ID.
	 *
	 * One query against the posts table, because a coupon's code *is* its
	 * `post_title` — the same fact `project()` relies on. There is nothing to
	 * build and no meta to read.
	 *
	 * A placeholder per ID is acceptable here for the reason it is nowhere else:
	 * the callers name a page or a chunk of one, never the shop.
	 *
	 * @param list<CouponId> $ids The coupons wanted.
	 *
	 * @return array<int, string>
	 */
	public function codes( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		// Aliased so that WPCS can recognise the prepare() call; the sniff only
		// follows a variable literally named $wpdb.
		$wpdb = $this->wpdb;

		$wanted = array_values( array_map( static fn ( CouponId $id ): int => $id->value, $ids ) );

		// Concatenated from literals rather than built with implode(), so that
		// the statement stays a literal-string for prepare().
		$placeholders = '%d';

		for ( $i = count( $wanted ); $i > 1; $i-- ) {
			$placeholders .= ', %d';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ID, post_title
				FROM %i
				WHERE post_type = %s
					AND ID IN ( ' . $placeholders . ' )',
				array_merge( array( $wpdb->posts, self::POST_TYPE ), $wanted )
			),
			ARRAY_A
		);
		// phpcs:enable

		$codes = array();

		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$codes[ (int) $row['ID'] ] = (string) $row['post_title'];
			}
		}

		return $codes;
	}

	/**
	 * How many coupons the audit holds.
	 *
	 * Two statements, on the same schema as every other read here, rather than
	 * WP_Query. It used to fetch every coupon's ID through `get_posts()` and
	 * count the array — twenty-six thousand rows moved to produce one number,
	 * and no way to ask whether a coupon applies itself, so it could not follow
	 * the rule the other reads follow and disagreed with them by design.
	 *
	 * The reachable ones are counted in the database. The rest are asked for by
	 * ID and judged, which is the same handful `usable()` judges and usually
	 * none at all.
	 *
	 * `dfxcaaw_coupon_rows_where` applies here as it does everywhere.
	 */
	public function count(): int {
		// Aliased so that WPCS can recognise the prepare() call; the sniff only
		// follows a variable literally named $wpdb.
		$wpdb = $this->wpdb;

		$where = $this->excluded_by_filter();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$reachable = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*)
				FROM %i p
				WHERE p.post_type = %s
					AND FIND_IN_SET( p.post_status, %s )',
				$wpdb->posts,
				self::POST_TYPE,
				implode( ',', self::LIVE_STATUSES )
			) . $where
		);

		return $reachable + count( $this->auto_applied_among_unreachable() );
	}

	/**
	 * The unreachable coupons that something applies without them being typed.
	 *
	 * Separated from `count()` because both it and `usable()` need exactly this
	 * and neither should ask twice, and because it is the one part of counting
	 * that cannot be done in the database: whether a coupon applies itself is
	 * answered by a filter holding a `WC_Coupon`.
	 *
	 * @return array<int, true> Coupon IDs, as a set.
	 */
	private function auto_applied_among_unreachable(): array {
		$wpdb = $this->wpdb;

		$where = $this->excluded_by_filter();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT p.ID
				FROM %i p
				WHERE p.post_type = %s
					AND FIND_IN_SET( p.post_status, %s )
					AND NOT FIND_IN_SET( p.post_status, %s )',
				$wpdb->posts,
				self::POST_TYPE,
				implode( ',', $this->listed_statuses() ),
				implode( ',', self::LIVE_STATUSES )
			) . $where
		);

		$wanted = array();

		foreach ( (array) $ids as $id ) {
			$wanted[] = new CouponId( (int) $id );
		}

		if ( array() === $wanted ) {
			return array();
		}

		$keep = array();

		foreach ( $this->some( $wanted ) as $coupon ) {
			if ( $coupon->is_auto_applied ) {
				$keep[ $coupon->id->value ] = true;
			}
		}

		return $keep;
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
	/**
	 * Every coupon, as scalars, in a fixed number of queries.
	 *
	 * This was one pivot: a nine-key `GROUP BY` over the join of posts and
	 * postmeta. It was correct and it was slow — seven seconds at twenty-five
	 * thousand coupons, nearly all of it in the database, because the pivot
	 * builds a temporary table over a quarter of a million joined rows.
	 *
	 * A handful of plain reads do the same work in about a third of the time:
	 *
	 * 1. the coupons themselves, straight off the `type_status_date` index;
	 * 2. one flat map per meta value the audit reads;
	 * 3. which coupons have a restriction at all.
	 *
	 * The third is what keeps this small as well as quick. `is_universal` only
	 * ever asks whether the four restriction lists are empty, and those lists
	 * are the large values in coupon meta — a category coupon can carry a long
	 * one. Asking the database for them in order to compute a boolean moved a
	 * hundred megabytes to decide twenty-six thousand yes-or-nos, so the
	 * emptiness is decided in SQL and only the IDs of the restricted coupons
	 * come back. The test that pins this reads the queries and fails if one of
	 * them ever asks for `meta_value` alongside a list key.
	 *
	 * Nothing is ordered. The screen sorts projections itself — it has to, since
	 * status is derived rather than stored — so an `ORDER BY` here would be a
	 * filesort whose result is thrown away.
	 *
	 * @return list<CouponProjection>
	 */
	public function project(): array {
		$rows = $this->usable( $this->coupon_rows() );

		if ( array() === $rows ) {
			return array();
		}

		$expires    = $this->meta_column( 'date_expires' );
		$limits     = $this->meta_column( 'usage_limit' );
		$counts     = $this->meta_column( 'usage_count' );
		$types      = $this->meta_column( 'discount_type' );
		$sale_items = $this->meta_column( 'exclude_sale_items' );
		$restricted = $this->restricted_coupons();
		$last_used  = $this->last_used_for_all();

		$projections = array();

		foreach ( $rows as $row ) {
			$id     = (int) $row['ID'];
			$status = (string) $row['post_status'];
			$limit  = (int) ( $limits[ $id ] ?? 0 );

			$projections[] = new CouponProjection(
				new CouponId( $id ),
				(string) $row['post_title'],
				in_array( $status, self::LIVE_STATUSES, true ),
				$this->local_datetime( (string) $row['post_date'] ),
				'future' === $status ? $this->local_datetime( (string) $row['post_date'] ) : null,
				$this->expiry_from_meta( $expires[ $id ] ?? null ),
				$limit > 0 ? $limit : null,
				(int) ( $counts[ $id ] ?? 0 ),
				isset( $last_used[ $id ] ) ? $this->local_datetime( $last_used[ $id ] ) : null,
				! isset( $restricted[ $id ] ) && 'yes' !== ( $sale_items[ $id ] ?? 'no' ),
				(string) ( $types[ $id ] ?? '' )
			);
		}

		return $projections;
	}

	/**
	 * The shop's own exclusion, as a SQL fragment to append to a WHERE.
	 *
	 * Some shops mint a coupon per customer. A points-and-rewards install can
	 * hold tens of thousands of generated codes that nobody audits, and they
	 * would bury the real ones. Excluding them has to happen in the query:
	 * anything applied afterwards means the shop has already paid to read them,
	 * which is the cost this whole class is arranged around not paying.
	 *
	 * The same fragment goes into every read of the shop's coupons — the bulk
	 * statements here and the WP_Query behind `all()` and `count()` — so one
	 * shop has one exclusion and the three cannot disagree about what it holds.
	 *
	 * That is why the contract names columns without a table. WP_Query writes
	 * the posts table by its full name and the statements here alias it `p`, so
	 * a condition qualified either way would fit only half of them.
	 *
	 * @return string A fragment beginning with ` AND `, or an empty string.
	 */
	private function excluded_by_filter(): string {
		$wpdb = $this->wpdb;

		/**
		 * Filters the coupons the audit reads, as a SQL fragment.
		 *
		 * Appended to the WHERE clause of every read of the shop's coupons: the
		 * bulk statements the audit screen uses, and the WP_Query behind `all()`
		 * and `count()`, which the exports and the pre-publish check use.
		 *
		 * Name columns without a table. WP_Query writes the posts table by its
		 * full name and the bulk statements alias it `p`, so a condition
		 * qualified either way fits only half of them.
		 *
		 * Begin with ` AND `, and escape everything: this is interpolated into
		 * the statement, so whatever answers is responsible for its own quoting,
		 * exactly as it is for WordPress's own `posts_where`.
		 *
		 *     add_filter(
		 *         'dfxcaaw_coupon_rows_where',
		 *         fn ( $where, $wpdb ) => $where . $wpdb->prepare(
		 *             ' AND post_title NOT LIKE %s',
		 *             $wpdb->esc_like( 'reward-' ) . '%'
		 *         ),
		 *         10,
		 *         2
		 *     );
		 *
		 * This replaced `dfxcaaw_coupon_query_args`, which reached `get_posts()`
		 * and so stopped applying to the audit screen when that screen was
		 * rewritten to read scalars in bulk. The old hook was removed in 0.9.0
		 * rather than kept, because what was left of it fired only below the
		 * three-hundred-coupon overlap cap: a filter that worked on a small shop
		 * and quietly did nothing on a large one. This one applies to every
		 * read, at every size, which is the point of it.
		 *
		 * @since 0.7.1
		 *
		 * @param string $where A SQL fragment, beginning with ` AND `, or ''.
		 * @param \wpdb  $wpdb  The database handle, for prepare() and esc_like().
		 */
		$where = apply_filters( 'dfxcaaw_coupon_rows_where', '', $wpdb );

		// A listener that answers with something other than a string is ignored
		// rather than interpolated. The alternative is a broken statement and an
		// empty audit, and an empty audit reads to a shop as though its coupons
		// have gone.
		return is_string( $where ) ? $where : '';
	}

	/**
	 * Run a WP_Query-backed read with the shop's exclusion applied.
	 *
	 * `all()` and `count()` go through `get_posts()`, which builds its own
	 * statement — so the fragment reaches them the way WordPress intends one to,
	 * through `posts_where`, rather than by this class trying to rewrite what
	 * WP_Query wrote.
	 *
	 * The filter checks the post type before touching anything. `posts_where`
	 * fires for every query made while it is attached, and something hooked to
	 * `pre_get_posts` is entitled to run one of its own; narrowing somebody
	 * else's query with this shop's coupon exclusion would be a strange and
	 * very quiet bug.
	 *
	 * @template T
	 *
	 * @param callable(): T $read The read to perform.
	 *
	 * @return T
	 */
	private function excluding( callable $read ): mixed {
		$where = $this->excluded_by_filter();

		if ( '' === $where ) {
			return $read();
		}

		$apply = static function ( $sql, $query ) use ( $where ) {
			return $query instanceof WP_Query && self::POST_TYPE === $query->get( 'post_type' )
				? $sql . $where
				: $sql;
		};

		add_filter( 'posts_where', $apply, 10, 2 );

		try {
			return $read();
		} finally {
			remove_filter( 'posts_where', $apply, 10 );
		}
	}

	/**
	 * The coupons themselves, without their meta.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function coupon_rows(): array {
		// Aliased so that WPCS can recognise the prepare() call; the sniff only
		// follows a variable literally named $wpdb.
		$wpdb = $this->wpdb;

		/*
		 * Worked out once and used by every read below that touches the posts
		 * table. See excluded_by_filter() for the contract.
		 */
		$where = $this->excluded_by_filter();

		// The statuses are not constant — they come from get_post_stati() — so
		// they go through a single placeholder as a comma-separated set rather
		// than a placeholder each, which would make the statement's length
		// follow the number of registered statuses. Table names go through %i
		// because prepare() wants a literal string and an interpolated
		// $wpdb->posts is not one.
		//
		// The filtered fragment is concatenated *after* prepare() rather than
		// inside it, which is what keeps the statement above a literal-string
		// and the placeholders it carries honest. What the filter returns is
		// its own responsibility, and the docblock says so.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT p.ID, p.post_title, p.post_status, p.post_date
				FROM %i p
				WHERE p.post_type = %s
					AND FIND_IN_SET( p.post_status, %s )',
				$wpdb->posts,
				self::POST_TYPE,
				implode( ',', $this->listed_statuses() )
			) . $where,
			ARRAY_A
		);

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Drop the coupons nobody can enter and nothing applies for them.
	 *
	 * Asked only of the coupons it could remove. Deciding whether a coupon
	 * applies itself means asking `dfxcaaw_coupon_is_auto_applied`, which is
	 * handed a `WC_Coupon` — the expensive object this whole class is arranged
	 * around not building. Asking it about every coupon would build twenty-six
	 * thousand of them and undo the reason any of this reads scalars.
	 *
	 * Asking it about the private and the draft ones is different: a shop has a
	 * handful, and a shop with none is never asked at all. A shop with tens of
	 * thousands of drafts would pay for them, and would also be a shop with a
	 * stranger problem than this.
	 *
	 * @param list<array<string, mixed>> $rows The coupons as read.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function usable( array $rows ): array {
		$questionable = array();

		foreach ( $rows as $row ) {
			if ( ! self::is_reachable( (string) ( $row['post_status'] ?? '' ) ) ) {
				$questionable[] = new CouponId( (int) $row['ID'] );
			}
		}

		if ( array() === $questionable ) {
			return $rows;
		}

		$keep = array();

		foreach ( $this->some( $questionable ) as $coupon ) {
			if ( $coupon->is_auto_applied ) {
				$keep[ $coupon->id->value ] = true;
			}
		}

		return array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $keep ): bool {
					return self::is_reachable( (string) ( $row['post_status'] ?? '' ) )
						|| isset( $keep[ (int) $row['ID'] ] );
				}
			)
		);
	}

	/**
	 * One meta key's value for every coupon, as a flat map.
	 *
	 * A key each rather than all five at once, which is the difference between
	 * one result of a hundred and twenty-five thousand three-column rows and
	 * five results of twenty-five thousand two-column ones. The first costs
	 * seventy megabytes at the customer's size and the second costs almost
	 * nothing, because each result is turned into a flat array and released
	 * before the next is asked for. It is about a fifth of a second slower, on
	 * a screen that has to fit inside a 256MB admin.
	 *
	 * Still a fixed number of queries. That is the invariant — not the number
	 * itself, but that it does not follow the number of coupons.
	 *
	 * @param string $key The meta key to read.
	 *
	 * @return array<int, string>
	 */
	private function meta_column( string $key ): array {
		// Aliased so that WPCS can recognise the prepare() call; the sniff only
		// follows a variable literally named $wpdb.
		$wpdb = $this->wpdb;

		$where = $this->excluded_by_filter();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT m.post_id, m.meta_value
				FROM %i m
				JOIN %i p
					ON p.ID = m.post_id
					AND p.post_type = %s
					AND FIND_IN_SET( p.post_status, %s )
				WHERE m.meta_key = %s',
				$wpdb->postmeta,
				$wpdb->posts,
				self::POST_TYPE,
				implode( ',', $this->listed_statuses() ),
				$key
			) . $where,
			ARRAY_N
		);

		$values = array();

		foreach ( (array) $rows as $row ) {
			$values[ (int) $row[0] ] = (string) $row[1];
		}

		return $values;
	}

	/**
	 * Which coupons are restricted to some part of the catalogue.
	 *
	 * Only the IDs come back. The lists themselves are the largest values in
	 * coupon meta and the audit never reads one — it asks whether there is one —
	 * so the emptiness is decided here, where the rows already are.
	 *
	 * The two values treated as empty are the two the snapshot path treats as
	 * empty: nothing at all, and a serialised empty array. A stored '0' counts
	 * as a restriction in both, which is odd but is what WooCommerce means by
	 * it, and this is not the place to start disagreeing.
	 *
	 * @return array<int, true>
	 */
	private function restricted_coupons(): array {
		$wpdb = $this->wpdb;

		$where = $this->excluded_by_filter();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT m.post_id
				FROM %i m
				JOIN %i p
					ON p.ID = m.post_id
					AND p.post_type = %s
					AND FIND_IN_SET( p.post_status, %s )
				WHERE m.meta_key IN (
					'product_ids', 'exclude_product_ids',
					'product_categories', 'exclude_product_categories'
				)
					AND TRIM( m.meta_value ) NOT IN ( '', 'a:0:{}' )",
				$wpdb->postmeta,
				$wpdb->posts,
				self::POST_TYPE,
				implode( ',', $this->listed_statuses() )
			) . $where
		);

		$restricted = array();

		foreach ( (array) $ids as $id ) {
			$restricted[ (int) $id ] = true;
		}

		return $restricted;
	}

	/**
	 * When each coupon was last redeemed, for the whole shop at once.
	 *
	 * Grouped over the lookup table with no IN list: the aggregate is the same
	 * work whether it is asked about one coupon or all of them, and asking about
	 * all of them by name is what does not scale.
	 *
	 * @return array<int, string>
	 */
	private function last_used_for_all(): array {
		if ( ! $this->has_lookup_table() ) {
			return array();
		}

		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT coupon_id, MAX(date_created) AS last_used FROM %i GROUP BY coupon_id',
				$this->lookup_table()
			)
		);

		$last_used = array();

		foreach ( (array) $rows as $row ) {
			$last_used[ (int) $row->coupon_id ] = (string) $row->last_used;
		}

		return $last_used;
	}

	/**
	 * A coupon's expiry, from the raw meta value WooCommerce stores.
	 *
	 * It keeps a Unix timestamp there, and an empty string for no expiry. The
	 * snapshot path reaches the same instant through WC_Coupon; this reaches it
	 * from the column, and the two are asserted to agree.
	 *
	 * @param mixed $stored The raw meta value.
	 */
	private function expiry_from_meta( $stored ): ?DateTimeImmutable {
		if ( ! is_scalar( $stored ) || '' === (string) $stored ) {
			return null;
		}

		return $this->local_datetime( gmdate( 'Y-m-d H:i:s', (int) $stored ) );
	}

	/**
	 * The query that lists coupons for the audit.
	 *
	 * @return array<string, mixed>
	 */
	private function query_args(): array {
		/*
		 * Not filterable. `dfxcaaw_coupon_query_args` used to be applied here
		 * and was removed in 0.9.0, because of where it had ended up rather
		 * than because anything was wrong with it.
		 *
		 * It reached `get_posts()`, and the audit screen stopped reading that
		 * way when it was rewritten to read scalars in bulk. What was left fired
		 * only from `all()`, which in production runs only below the
		 * three-hundred-coupon overlap cap — so the filter worked on a small
		 * shop and silently did nothing on a large one. A hook whose behaviour
		 * depends on the size of the shop is worse than no hook: it is tested on
		 * a small install, believed, and wrong in production, which is exactly
		 * how the audit came to be missing exclusions in the first place.
		 *
		 * `dfxcaaw_coupon_rows_where` does the same job on every read at every
		 * size, and is applied to this query too, through `posts_where`.
		 */
		return array(
			'post_type'        => self::POST_TYPE,
			'post_status'      => $this->listed_statuses(),
			'numberposts'      => -1,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'suppress_filters' => false,
		);
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
