<?php
/**
 * What runs when a new version of the plugin arrives.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Install;

use DFX\CouponAAW\Support\SettingsInterface;

/**
 * Work that has to happen once per version, rather than once per activation.
 *
 * Activation is not the same event as an update: WordPress runs the activation
 * hook when a plugin is switched on and not when it is replaced with a newer
 * copy, so anything that only `Activator` does is skipped by every shop that
 * simply presses update — which is nearly all of them.
 *
 * What this does today is give the days that would not aggregate another go.
 * That matters because of how they usually fail: not because the shop's database
 * was briefly away, but because this plugin could not read something the shop
 * legitimately holds. Retries cannot fix that and a new version can, so a new
 * version is the moment worth trying them again.
 */
final class Upgrader {

	/**
	 * Where the version last seen is remembered.
	 */
	private const VERSION_KEY = 'plugin_version';

	/**
	 * Constructor.
	 *
	 * @param SettingsInterface $settings   Remembers the version last seen.
	 * @param SchemaMigrator    $schema     Creates or upgrades the aggregates table.
	 * @param Aggregator        $aggregator Holds the days that would not aggregate.
	 */
	public function __construct(
		private readonly SettingsInterface $settings,
		private readonly SchemaMigrator $schema,
		private readonly Aggregator $aggregator
	) {}

	/**
	 * Bring the store up to date with the version now running.
	 *
	 * Cheap and idempotent, because it runs on every load: one setting is read
	 * and, on the overwhelming majority of loads, nothing else happens. It has
	 * to run there rather than on a hook, because there is no reliable hook for
	 * "this plugin was just updated" — `upgrader_process_complete` misses an
	 * update made over FTP, over WP-CLI, or by a deployment.
	 *
	 * @param string $version The version now running.
	 */
	public function run( string $version ): void {
		if ( $this->settings->get_string( self::VERSION_KEY ) === $version ) {
			return;
		}

		if ( $this->schema->needs_upgrade() ) {
			$this->schema->migrate();
		}

		$this->aggregator->retry_failed_days();

		$this->settings->set( self::VERSION_KEY, $version );
	}
}
