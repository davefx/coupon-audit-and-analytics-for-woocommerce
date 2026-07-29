<?php
/**
 * Core bindings.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Providers;

use DateTimeZone;
use DFX\CouponAAW\Container\ContainerInterface;
use DFX\CouponAAW\Container\ServiceProviderInterface;
use DFX\CouponAAW\Domain\Clock\ClockInterface;
use DFX\CouponAAW\Domain\Clock\SystemClock;
use DFX\CouponAAW\Domain\Coupon\OrphanDetector;
use DFX\CouponAAW\Domain\Coupon\StatusResolver;
use DFX\CouponAAW\Repository\CouponRepositoryInterface;
use DFX\CouponAAW\Repository\WpCouponRepository;
use DFX\CouponAAW\Support\PluginContext;
use wpdb;

/**
 * Contributes the bindings that every other slice of the plugin depends on.
 *
 * This is where the mandatory test seams of §10.4 are wired. The clock is the
 * first of them; repositories, the feature gate and the options wrapper join it
 * as each is built.
 */
final class CoreServiceProvider implements ServiceProviderInterface {

	/**
	 * Days without a redemption before a coupon counts as dormant.
	 *
	 * §8.4 requires this to be configurable. Until the settings wrapper exists
	 * it is bound to the documented default rather than read from an option,
	 * because §10.4 forbids reaching for `get_option()` from here.
	 */
	private const DEFAULT_DORMANCY_DAYS = 90;

	/**
	 * Constructor.
	 *
	 * @param PluginContext $context  Values supplied by the main plugin file.
	 * @param DateTimeZone  $timezone The site's timezone.
	 */
	public function __construct(
		private readonly PluginContext $context,
		private readonly DateTimeZone $timezone
	) {}

	/**
	 * Register bindings.
	 *
	 * @param ContainerInterface $container Service container.
	 */
	public function register( ContainerInterface $container ): void {
		$container->instance( PluginContext::class, $this->context );

		$container->bind(
			ClockInterface::class,
			fn (): ClockInterface => new SystemClock( $this->timezone )
		);

		$container->bind(
			StatusResolver::class,
			static fn ( ContainerInterface $c ): StatusResolver => new StatusResolver(
				$c->get( ClockInterface::class )
			)
		);

		/*
		 * The database handle is fetched when the repository is first resolved
		 * rather than when this provider is built, so that constructing the
		 * provider — which the unit suite does — never needs a live $wpdb.
		 */
		$container->bind(
			CouponRepositoryInterface::class,
			function (): CouponRepositoryInterface {
				global $wpdb;

				return new WpCouponRepository( $wpdb, $this->timezone );
			}
		);

		$container->bind(
			OrphanDetector::class,
			static fn ( ContainerInterface $c ): OrphanDetector => new OrphanDetector(
				$c->get( StatusResolver::class ),
				$c->get( ClockInterface::class ),
				self::DEFAULT_DORMANCY_DAYS
			)
		);
	}

	/**
	 * Wire services into WordPress.
	 *
	 * Nothing to hook at this milestone: the core slice contributes values and
	 * domain services, none of which listen to WordPress directly.
	 *
	 * @param ContainerInterface $container Service container.
	 */
	public function boot( ContainerInterface $container ): void {}
}
