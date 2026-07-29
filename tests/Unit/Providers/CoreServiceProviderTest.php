<?php
/**
 * Core service provider unit tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Providers;

use DateTimeZone;
use DFX\CouponAAW\Container\Container;
use DFX\CouponAAW\Domain\Clock\ClockInterface;
use DFX\CouponAAW\Domain\Clock\SystemClock;
use DFX\CouponAAW\Domain\Coupon\OrphanDetector;
use DFX\CouponAAW\Domain\Coupon\StatusResolver;
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
	 * The site timezone handed to the provider.
	 *
	 * @var DateTimeZone
	 */
	private DateTimeZone $timezone;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = new Container();
		$this->timezone  = new DateTimeZone( 'Europe/Madrid' );
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
		( new CoreServiceProvider( $this->context, $this->timezone ) )->register( $this->container );

		$this->assertSame( $this->context, $this->container->get( PluginContext::class ) );
	}

	/**
	 * Construction is inert: it is `register()` that touches the container.
	 * A provider must be safe to build early and wire late.
	 */
	public function test_it_binds_nothing_until_registration(): void {
		$provider = new CoreServiceProvider( $this->context, $this->timezone );

		$this->assertFalse( $this->container->has( PluginContext::class ) );

		$provider->register( $this->container );

		$this->assertTrue( $this->container->has( PluginContext::class ) );
	}

	/**
	 * Booting has nothing to hook yet, and must stay harmless.
	 */
	public function test_booting_leaves_the_registered_bindings_intact(): void {
		$provider = new CoreServiceProvider( $this->context, $this->timezone );
		$provider->register( $this->container );

		$provider->boot( $this->container );

		$this->assertSame( $this->context, $this->container->get( PluginContext::class ) );
	}

	/**
	 * The clock seam of §10.4. Nothing in the codebase reads the real time
	 * except the object bound here.
	 */
	public function test_it_registers_the_clock(): void {
		( new CoreServiceProvider( $this->context, $this->timezone ) )->register( $this->container );

		$clock = $this->container->get( ClockInterface::class );

		$this->assertInstanceOf( SystemClock::class, $clock );
	}

	/**
	 * The clock reports in the site's timezone, not the server's. A store in
	 * Madrid whose server runs UTC must not see a coupon expire two hours early.
	 */
	public function test_the_clock_uses_the_timezone_it_was_given(): void {
		( new CoreServiceProvider( $this->context, $this->timezone ) )->register( $this->container );

		$this->assertSame(
			'Europe/Madrid',
			$this->container->get( ClockInterface::class )->now()->getTimezone()->getName()
		);
	}

	/**
	 * The domain services are resolvable and share the one clock.
	 */
	public function test_it_registers_the_coupon_domain_services(): void {
		( new CoreServiceProvider( $this->context, $this->timezone ) )->register( $this->container );

		$this->assertInstanceOf( StatusResolver::class, $this->container->get( StatusResolver::class ) );
		$this->assertInstanceOf( OrphanDetector::class, $this->container->get( OrphanDetector::class ) );
	}

	/**
	 * Services are shared, so resolving twice does not build two clocks that
	 * could disagree with each other mid-request.
	 */
	public function test_the_registered_services_are_shared(): void {
		( new CoreServiceProvider( $this->context, $this->timezone ) )->register( $this->container );

		$this->assertSame(
			$this->container->get( ClockInterface::class ),
			$this->container->get( ClockInterface::class )
		);
		$this->assertSame(
			$this->container->get( StatusResolver::class ),
			$this->container->get( StatusResolver::class )
		);
	}
}
