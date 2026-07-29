<?php
/**
 * Core service provider unit tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Providers;

use DFX\CouponAAW\Container\Container;
use DFX\CouponAAW\Providers\CoreServiceProvider;
use DFX\CouponAAW\Support\PluginContext;
use PHPUnit\Framework\TestCase;

/**
 * The core provider contributes the bindings every other slice of the plugin
 * depends on. At this milestone that is the plugin context alone; the domain
 * seams of §10.4 land here as they are built.
 */
final class CoreServiceProviderTest extends TestCase {

	/**
	 * A fresh container per test.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * The context handed to the provider.
	 *
	 * @var PluginContext
	 */
	private PluginContext $context;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = new Container();
		$this->context   = new PluginContext(
			version: '0.1.0',
			main_file: '/plugins/cpn/coupon-audit-and-analytics-for-woocommerce.php',
			directory: '/plugins/cpn/',
			base_url: 'https://shop.test/wp-content/plugins/cpn/',
			slug: 'coupon-audit-and-analytics-for-woocommerce'
		);
	}

	/**
	 * The context becomes resolvable, so no class further in needs a constant.
	 */
	public function test_it_registers_the_plugin_context(): void {
		( new CoreServiceProvider( $this->context ) )->register( $this->container );

		$this->assertSame( $this->context, $this->container->get( PluginContext::class ) );
	}

	/**
	 * Construction is inert: it is `register()` that touches the container.
	 * A provider must be safe to build early and wire late.
	 */
	public function test_it_binds_nothing_until_registration(): void {
		$provider = new CoreServiceProvider( $this->context );

		$this->assertFalse( $this->container->has( PluginContext::class ) );

		$provider->register( $this->container );

		$this->assertTrue( $this->container->has( PluginContext::class ) );
	}

	/**
	 * Booting has nothing to hook yet, and must stay harmless.
	 */
	public function test_booting_leaves_the_registered_bindings_intact(): void {
		$provider = new CoreServiceProvider( $this->context );
		$provider->register( $this->container );

		$provider->boot( $this->container );

		$this->assertSame( $this->context, $this->container->get( PluginContext::class ) );
	}
}
