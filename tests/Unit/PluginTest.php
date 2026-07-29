<?php
/**
 * Plugin bootstrap unit tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit;

use DFX\CouponAAW\Container\Container;
use DFX\CouponAAW\Container\ContainerInterface;
use DFX\CouponAAW\Container\ServiceProviderInterface;
use DFX\CouponAAW\Plugin;
use DFX\CouponAAW\Tests\Fixtures\CallLog;
use DFX\CouponAAW\Tests\Fixtures\RecordingServiceProvider;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * The first of the two singletons (§3.3). It owns no business logic: it holds
 * the container, collects providers and runs the two-phase boot. That it calls
 * no WordPress function at all is what keeps these tests this cheap.
 */
final class PluginTest extends TestCase {

	/**
	 * The container handed to the plugin under test.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * The subject under test.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = new Container();
		$this->plugin    = new Plugin( $this->container );
	}

	/**
	 * The container the plugin was built with is the one it exposes.
	 */
	public function test_it_exposes_its_container(): void {
		$this->assertSame( $this->container, $this->plugin->container() );
	}

	/**
	 * Providers are added fluently, so the main file reads as a list.
	 */
	public function test_adding_a_provider_is_fluent(): void {
		$this->assertSame(
			$this->plugin,
			$this->plugin->add_provider( new RecordingServiceProvider( 'core', new CallLog() ) )
		);
	}

	/**
	 * Every provider registers before any provider boots. A provider's boot
	 * phase may depend on bindings another provider contributed, so the two
	 * phases must not interleave.
	 */
	public function test_all_providers_register_before_any_of_them_boots(): void {
		$log = new CallLog();

		$this->plugin
			->add_provider( new RecordingServiceProvider( 'first', $log ) )
			->add_provider( new RecordingServiceProvider( 'second', $log ) )
			->boot();

		$this->assertSame(
			array( 'first:register', 'second:register', 'first:boot', 'second:boot' ),
			$log->entries()
		);
	}

	/**
	 * Providers receive the plugin's container, not one of their own.
	 */
	public function test_it_passes_its_container_to_each_provider(): void {
		$seen = new CallLog();

		$this->plugin->add_provider(
			new class( $seen ) implements ServiceProviderInterface {

				/**
				 * Constructor.
				 *
				 * @param CallLog $seen Containers observed, in call order.
				 */
				public function __construct( private readonly CallLog $seen ) {}

				/**
				 * Record the container.
				 *
				 * @param ContainerInterface $container Service container.
				 */
				public function register( ContainerInterface $container ): void {
					$this->seen->record( $container );
				}

				/**
				 * Record the container.
				 *
				 * @param ContainerInterface $container Service container.
				 */
				public function boot( ContainerInterface $container ): void {
					$this->seen->record( $container );
				}
			}
		)->boot();

		$this->assertSame( array( $this->container, $this->container ), $seen->entries() );
	}

	/**
	 * Booting twice does nothing the second time. WordPress fires hooks in ways
	 * that are hard to predict; a double boot must not double-register.
	 */
	public function test_booting_twice_is_a_no_op(): void {
		$log = new CallLog();

		$this->plugin->add_provider( new RecordingServiceProvider( 'core', $log ) );
		$this->plugin->boot();
		$this->plugin->boot();

		$this->assertSame( array( 'core:register', 'core:boot' ), $log->entries() );
	}

	/**
	 * The plugin reports whether it has booted.
	 */
	public function test_it_reports_whether_it_has_booted(): void {
		$this->assertFalse( $this->plugin->is_booted() );

		$this->plugin->boot();

		$this->assertTrue( $this->plugin->is_booted() );
	}

	/**
	 * A provider added after boot would be silently ignored, which is a bug
	 * that surfaces far from its cause. Refuse it instead.
	 */
	public function test_adding_a_provider_after_boot_is_rejected(): void {
		$this->plugin->boot();

		$this->expectException( LogicException::class );

		$this->plugin->add_provider( new RecordingServiceProvider( 'late', new CallLog() ) );
	}

	/**
	 * Booting with no providers at all is legitimate and does nothing.
	 */
	public function test_it_boots_without_providers(): void {
		$this->plugin->boot();

		$this->assertTrue( $this->plugin->is_booted() );
	}

	/**
	 * The singleton accessor always hands back the same plugin.
	 */
	public function test_get_instance_returns_the_canonical_plugin(): void {
		$this->assertSame( Plugin::get_instance(), Plugin::get_instance() );
	}

	/**
	 * The canonical plugin uses the canonical container.
	 */
	public function test_the_canonical_plugin_uses_the_canonical_container(): void {
		$this->assertSame( Container::get_instance(), Plugin::get_instance()->container() );
	}
}
