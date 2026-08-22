<?php
/**
 * Removing the plugin's data when it is deleted.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Install;

/**
 * What deleting the plugin removes, and what it does not (§14).
 *
 * Nothing goes unless the user ticked the box on the settings screen. Analytics
 * data is expensive to rebuild — a backfill walks a store's whole order history
 * a day at a time — and deleting a plugin is not consent to destroy it. Someone
 * swapping hosts, or removing the plugin to test whether it is the cause of
 * something, should get their figures back when they reinstall.
 *
 * This is hung on Freemius's `after_uninstall` rather than living in an
 * uninstall.php, and that is not a preference. WordPress runs uninstall.php
 * *instead of* the uninstall hooks a plugin has registered, so a plugin that
 * ships both silently loses the hooks — which is why Freemius refuses to accept
 * a package containing one, and how this arrangement was arrived at.
 */
final class Uninstaller {

	/**
	 * The option holding the user's settings, the tick box among them.
	 */
	private const SETTINGS_OPTION = 'dfxcaaw_settings';

	/**
	 * The setting that has to be true before anything is removed.
	 */
	private const CONSENT_KEY = 'delete_data_on_uninstall';

	/**
	 * Constructor.
	 *
	 * @param \wpdb $wpdb The database.
	 */
	public function __construct( private readonly \wpdb $wpdb ) {}

	/**
	 * Remove everything, if the user asked for that.
	 */
	public function uninstall(): void {
		if ( ! $this->consented() ) {
			return;
		}

		foreach ( $this->tables() as $table ) {
			$this->drop( $table );
		}

		foreach ( $this->options() as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * Whether the user asked for their data to be removed.
	 */
	private function consented(): bool {
		$settings = get_option( self::SETTINGS_OPTION );

		return is_array( $settings ) && ! empty( $settings[ self::CONSENT_KEY ] );
	}

	/**
	 * Every table this plugin has ever created.
	 *
	 * The fees table belongs to the paid add-on and this build may never have
	 * created it. It is dropped anyway, because a customer who stops paying and
	 * removes the plugin should not be left with a table on account of having
	 * left from the free build. `IF EXISTS` makes that a no-op everywhere else.
	 *
	 * @return list<string>
	 */
	private function tables(): array {
		return array(
			$this->wpdb->prefix . 'dfxcaaw_coupon_stats',
			$this->wpdb->prefix . 'dfxcaaw_coupon_fees',
		);
	}

	/**
	 * Every option this plugin has ever set.
	 *
	 * The schema versions go with the tables. Leaving one behind would have a
	 * reinstall believe it already has a table it no longer has, and the first
	 * query would be the thing that found out.
	 *
	 * @return list<string>
	 */
	private function options(): array {
		return array(
			self::SETTINGS_OPTION,
			'dfxcaaw_db_version',
			'dfxcaaw_premium_fees_db_version',
		);
	}

	/**
	 * Drop one table.
	 *
	 * @param string $table The table's full name.
	 */
	private function drop( string $table ): void {
		// Aliased so that WPCS can recognise the prepare() call; the sniff only
		// follows a variable literally named $wpdb.
		$wpdb = $this->wpdb;

		$sql = $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table );

		if ( is_string( $sql ) ) {
			// $sql is the return of prepare() on the line above; the sniff cannot
			// follow it through the null check that PHPStan insists on.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $sql );
		}
	}
}
