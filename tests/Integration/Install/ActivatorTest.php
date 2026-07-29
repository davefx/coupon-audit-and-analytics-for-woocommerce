<?php
/**
 * Activation tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Integration\Install;

use DFX\CouponAAW\Install\Activator;
use DFX\CouponAAW\Install\SchemaMigrator;
use DFX\CouponAAW\Plugin;
use WP_UnitTestCase;

/**
 * What switching the plugin on does.
 *
 * Activation runs every time somebody toggles the plugin, not only the first
 * time, so what matters is that it is safe to run twice: the table is created
 * once, and a store that has already had its history walked does not walk it
 * again.
 */
final class ActivatorTest extends WP_UnitTestCase {

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		Plugin::get_instance()->container()->get( SchemaMigrator::class )->drop();
		delete_option( 'dfxcaaw_settings' );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'dfxcaaw' );
		}

		parent::tear_down();
	}

	/**
	 * The subject under test.
	 */
	private function activator(): Activator {
		return Plugin::get_instance()->container()->get( Activator::class );
	}

	/**
	 * Activation creates the table the margin screen reads from.
	 */
	public function test_activation_creates_the_aggregates_table(): void {
		$schema = Plugin::get_instance()->container()->get( SchemaMigrator::class );

		$schema->drop();

		$this->activator()->activate();

		$this->assertFalse(
			$schema->needs_upgrade(),
			'The aggregates table was not created, so every margin query will fail.'
		);
	}

	/**
	 * Activating twice is not an error. Deactivating and reactivating is the
	 * first thing anybody does when a plugin misbehaves.
	 */
	public function test_activating_twice_is_harmless(): void {
		$this->activator()->activate();
		$this->activator()->activate();

		$this->assertFalse(
			Plugin::get_instance()->container()->get( SchemaMigrator::class )->needs_upgrade()
		);
	}
}
