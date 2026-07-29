<?php
/**
 * Aggregates table schema.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Install;

use DFX\CouponAAW\Support\SettingsInterface;
use wpdb;

/**
 * Creates and upgrades the coupon aggregates table (§6.2).
 *
 * Joining orders, line items and coupons in real time does not scale, so the
 * figures behind the margin screen are precomputed into one table keyed by
 * coupon, day and currency.
 *
 * Currency is part of the key rather than a column beside it, which settles
 * §16's fourth open question: currencies aggregate separately, and a
 * multi-currency store gets one row per currency per day rather than a warning
 * and an exclusion. §8.5 already forbids summing them, so keying by currency
 * makes the wrong thing impossible instead of merely discouraged, and no store's
 * data is thrown away for the sake of a simpler key.
 *
 * Money is stored as an integer number of minor units, matching `Money`. A
 * DECIMAL column would be exact too, but every read would have to convert, and
 * the one thing §8.5 asks is that money never passes through a float.
 */
final class SchemaMigrator {

	/**
	 * The schema version this code expects.
	 *
	 * Bumped whenever the table below changes, and compared against what is
	 * stored so an upgrade knows to run.
	 */
	public const VERSION = 1;

	/**
	 * Where the installed version is recorded.
	 */
	private const VERSION_KEY = 'db_version';

	/**
	 * Constructor.
	 *
	 * @param wpdb              $wpdb     WordPress database handle.
	 * @param SettingsInterface $settings Where the installed version is recorded.
	 */
	public function __construct(
		private readonly wpdb $wpdb,
		private readonly SettingsInterface $settings
	) {}

	/**
	 * The aggregates table name.
	 */
	public function table_name(): string {
		return $this->wpdb->prefix . 'dfxcaaw_coupon_stats';
	}

	/**
	 * Whether the schema on disk is older than the schema this code expects.
	 */
	public function needs_upgrade(): bool {
		return $this->installed_version() < self::VERSION;
	}

	/**
	 * The schema version currently installed, or 0 if none.
	 */
	public function installed_version(): int {
		return (int) $this->settings->get( self::VERSION_KEY, 0 );
	}

	/**
	 * Create or update the table, and record the version.
	 *
	 * Safe to call repeatedly: `dbDelta` compares the existing table against the
	 * definition and issues only the differences.
	 */
	public function migrate(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $this->definition() );

		$this->settings->set( self::VERSION_KEY, self::VERSION );
	}

	/**
	 * Remove the table entirely.
	 *
	 * Only ever called from the uninstall routine, and only where the user has
	 * opted in (§14).
	 */
	public function drop(): void {
		// Aliased so that WPCS can recognise the prepare() call; the sniff only
		// follows a variable literally named $wpdb.
		$wpdb = $this->wpdb;

		$sql = $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->table_name() );

		if ( is_string( $sql ) ) {
			// $sql is the return of prepare() on the line above; the sniff cannot
			// follow it through the null check that PHPStan insists on.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $sql );
		}

		$this->settings->set( self::VERSION_KEY, 0 );
	}

	/**
	 * The table definition, in the shape `dbDelta` insists on.
	 *
	 * @return string
	 */
	private function definition(): string {
		$table   = $this->table_name();
		$collate = $this->wpdb->get_charset_collate();

		/*
		 * Written for both MySQL and MariaDB. Two things are deliberate:
		 *
		 * Integer display widths (`bigint(20)`) are gone. MySQL 8 dropped them
		 * from its own DESCRIBE output while MariaDB still reports them, so a
		 * definition carrying them makes dbDelta believe every column differs on
		 * one engine and not the other, and ALTER the table on every single run.
		 *
		 * `updated_at` has no zero-date default. '0000-00-00 00:00:00' is not a
		 * legal default under MySQL's NO_ZERO_DATE, which is on by default in
		 * MySQL 8; every insert supplies the value anyway.
		 */
		return "CREATE TABLE {$table} (
			coupon_id bigint unsigned NOT NULL,
			stat_date date NOT NULL,
			currency char(3) NOT NULL,
			orders int unsigned NOT NULL default 0,
			net_revenue bigint NOT NULL default 0,
			discount bigint NOT NULL default 0,
			cost bigint NOT NULL default 0,
			covered_lines int unsigned NOT NULL default 0,
			total_lines int unsigned NOT NULL default 0,
			cost_source varchar(32) NOT NULL default '',
			updated_at datetime NOT NULL,
			PRIMARY KEY  (coupon_id,stat_date,currency),
			KEY stat_date (stat_date)
		) {$collate};";
	}
}
