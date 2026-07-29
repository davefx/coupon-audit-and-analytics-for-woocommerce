<?php
/**
 * Schema migration integration tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Integration\Install;

use DFX\CouponAAW\Install\SchemaMigrator;
use DFX\CouponAAW\Support\WpOptionSettings;
use WP_UnitTestCase;

/**
 * Schema work can only be verified against a real database: `dbDelta` compares
 * what it is given with what exists, and its idea of a difference is famously
 * particular about whitespace and column order.
 */
final class SchemaMigratorTest extends WP_UnitTestCase {

	/**
	 * The subject under test.
	 *
	 * @var SchemaMigrator
	 */
	private SchemaMigrator $migrator;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$this->migrator = new SchemaMigrator( $wpdb, new WpOptionSettings() );
		$this->migrator->drop();
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		$this->migrator->drop();
		delete_option( 'dfxcaaw_settings' );

		parent::tear_down();
	}

	/**
	 * Whether the aggregates table exists right now.
	 */
	private function table_exists(): bool {
		global $wpdb;

		$table = $this->migrator->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * The columns the table actually has.
	 *
	 * @return list<string>
	 */
	private function columns(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col( $wpdb->prepare( 'DESCRIBE %i', $this->migrator->table_name() ) );

		return array_values( array_map( 'strval', (array) $rows ) );
	}

	/**
	 * A fresh install has no table and reports that it needs one.
	 */
	public function test_a_fresh_install_needs_the_schema(): void {
		$this->assertFalse( $this->table_exists() );
		$this->assertSame( 0, $this->migrator->installed_version() );
		$this->assertTrue( $this->migrator->needs_upgrade() );
	}

	/**
	 * Migrating creates the table and records the version.
	 */
	public function test_it_creates_the_table_and_records_the_version(): void {
		global $wpdb;

		$this->migrator->migrate();

		// The database's own complaint is reported, because "false is not true"
		// says nothing about a CREATE TABLE that one engine accepts and another
		// rejects.
		$this->assertTrue( $this->table_exists(), 'CREATE TABLE failed: ' . $wpdb->last_error );
		$this->assertSame( SchemaMigrator::VERSION, $this->migrator->installed_version() );
		$this->assertFalse( $this->migrator->needs_upgrade() );
	}

	/**
	 * Every column the aggregates need is present, including the cost source —
	 * a row has to be able to say which bookkeeping system produced its cost,
	 * or a store that switches plugins cannot be told why its numbers moved.
	 */
	public function test_the_table_has_the_columns_the_aggregates_need(): void {
		$this->migrator->migrate();

		$this->assertSame(
			array(
				'coupon_id',
				'stat_date',
				'currency',
				'orders',
				'net_revenue',
				'discount',
				'cost',
				'covered_lines',
				'total_lines',
				'cost_source',
				'updated_at',
			),
			$this->columns()
		);
	}

	/**
	 * Migrating twice is harmless, which matters because activation runs it
	 * every time a plugin is switched on.
	 */
	public function test_migrating_twice_changes_nothing(): void {
		global $wpdb;

		$this->migrator->migrate();
		$columns = $this->columns();

		$this->migrator->migrate();

		$this->assertTrue( $this->table_exists(), 'CREATE TABLE failed: ' . $wpdb->last_error );
		$this->assertSame( $columns, $this->columns() );
	}

	/**
	 * §16's fourth question, settled in the schema: a store selling in two
	 * currencies gets a row per currency per day rather than a warning and an
	 * exclusion. §8.5 forbids summing currencies, and putting currency in the
	 * key makes mixing them impossible rather than merely discouraged.
	 */
	public function test_the_same_coupon_and_day_can_hold_two_currencies(): void {
		global $wpdb;

		$this->migrator->migrate();

		$row = array(
			'coupon_id'   => 7,
			'stat_date'   => '2026-07-01',
			'orders'      => 1,
			'net_revenue' => 1000,
			'updated_at'  => '2026-07-01 00:00:00',
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( 1, $wpdb->insert( $this->migrator->table_name(), $row + array( 'currency' => 'EUR' ) ) );
		$this->assertSame( 1, $wpdb->insert( $this->migrator->table_name(), $row + array( 'currency' => 'USD' ) ) );

		$stored = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE coupon_id = %d AND stat_date = %s',
				$this->migrator->table_name(),
				7,
				'2026-07-01'
			)
		);
		// phpcs:enable

		$this->assertSame( 2, $stored, 'Two currencies for one coupon-day must coexist.' );
	}

	/**
	 * The same coupon, day and currency is one row, not many: re-aggregating a
	 * day must replace its figures rather than pile more on top.
	 */
	public function test_one_row_per_coupon_day_and_currency(): void {
		global $wpdb;

		$this->migrator->migrate();

		$row = array(
			'coupon_id'   => 7,
			'stat_date'   => '2026-07-01',
			'currency'    => 'EUR',
			'orders'      => 1,
			'net_revenue' => 1000,
			'updated_at'  => '2026-07-01 00:00:00',
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( $this->migrator->table_name(), $row );
		$wpdb->suppress_errors( true );
		$duplicate = $wpdb->insert( $this->migrator->table_name(), $row );
		$wpdb->suppress_errors( false );
		// phpcs:enable

		$this->assertFalse( $duplicate, 'A second row for the same key must be refused by the primary key.' );
	}

	/**
	 * Dropping removes the table and forgets the version, so a reinstall starts
	 * clean rather than believing a table exists that does not.
	 */
	public function test_dropping_removes_the_table_and_the_version(): void {
		$this->migrator->migrate();

		$this->migrator->drop();

		$this->assertFalse( $this->table_exists() );
		$this->assertSame( 0, $this->migrator->installed_version() );
		$this->assertTrue( $this->migrator->needs_upgrade() );
	}
}
