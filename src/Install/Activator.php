<?php
/**
 * Plugin activation.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Install;

/**
 * What has to happen the moment the plugin is switched on.
 *
 * Deliberately small, and deliberately not where the work happens. Activation
 * runs inside a request the user is waiting on, so it creates the table and
 * queues the history — it does not walk three years of orders while the browser
 * spins.
 */
final class Activator {

	/**
     * @var SchemaMigrator
     * @readonly
     */
    private SchemaMigrator $schema;
    /**
     * @var Aggregator
     * @readonly
     */
    private Aggregator $aggregator;
    /**
	 * Constructor.
	 *
	 * @param SchemaMigrator $schema     Creates or upgrades the aggregates table.
	 * @param Aggregator     $aggregator Queues the retroactive backfill.
	 */
	public function __construct(SchemaMigrator $schema, Aggregator $aggregator)
    {
        $this->schema = $schema;
        $this->aggregator = $aggregator;
    }

	/**
	 * Prepare the store.
	 */
	public function activate(): void {
		if ( $this->schema->needs_upgrade() ) {
			$this->schema->migrate();
		}

		$this->aggregator->start_backfill();
	}
}
