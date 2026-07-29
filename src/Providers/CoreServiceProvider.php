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
use DFX\CouponAAW\Catalog\CatalogRepositoryInterface;
use DFX\CouponAAW\Catalog\WcCatalogRepository;
use DFX\CouponAAW\Container\ServiceProviderInterface;
use DFX\CouponAAW\Domain\Coupon\ConfigurationAuditor;
use DFX\CouponAAW\Domain\Clock\ClockInterface;
use DFX\CouponAAW\Domain\Clock\SystemClock;
use DFX\CouponAAW\Domain\Coupon\OrphanDetector;
use DFX\CouponAAW\Domain\Coupon\StatusResolver;
use DFX\CouponAAW\Domain\Overlap\OverlapDetector;
use DFX\CouponAAW\Domain\Overlap\ScopeIndex;
use DFX\CouponAAW\Integration\IntegrationRegistry;
use DFX\CouponAAW\Integration\WjecfIntegration;
use DFX\CouponAAW\Integration\YithPointsIntegration;
use DFX\CouponAAW\Install\Activator;
use DFX\CouponAAW\Install\Aggregator;
use DFX\CouponAAW\Install\SchemaMigrator;
use DFX\CouponAAW\Repository\CouponRepositoryInterface;
use DFX\CouponAAW\Repository\CouponStatsRepositoryInterface;
use DFX\CouponAAW\Repository\OrderStatsRepositoryInterface;
use DFX\CouponAAW\Repository\WcOrderStatsRepository;
use DFX\CouponAAW\Repository\WpCouponStatsRepository;
use DFX\CouponAAW\Service\AggregationService;
use DFX\CouponAAW\Repository\WpCouponRepository;
use DFX\CouponAAW\Cost\BoosterCogsSource;
use DFX\CouponAAW\Cost\CostSourceRegistry;
use DFX\CouponAAW\Cost\NativeCogsSource;
use DFX\CouponAAW\Cost\SkyvergeCogsSource;
use DFX\CouponAAW\Cost\WpFactoryCogsSource;
use DFX\CouponAAW\Support\PluginContext;
use DFX\CouponAAW\Support\SettingsInterface;
use DFX\CouponAAW\Support\WpOptionSettings;
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

				return new WpCouponRepository( $wpdb, $this->timezone, wc_get_price_decimals() );
			}
		);

		$container->bind(
			SettingsInterface::class,
			static fn (): SettingsInterface => new WpOptionSettings()
		);

		/*
		 * The cost systems a store might be using. Which one is read is settled
		 * once, by the registry, rather than per line: a report that took one
		 * plugin's figure for one line and another's for the next would be a
		 * blend of two sets of books and reconcile with neither.
		 */
		$container->bind(
			CostSourceRegistry::class,
			static function ( ContainerInterface $c ): CostSourceRegistry {
				$currency = get_woocommerce_currency();
				$decimals = wc_get_price_decimals();

				return new CostSourceRegistry(
					array(
						new NativeCogsSource( $currency, $decimals ),
						new WpFactoryCogsSource( $currency, $decimals ),
						new SkyvergeCogsSource( $currency, $decimals ),
						new BoosterCogsSource( $currency, $decimals ),
					),
					$c->get( SettingsInterface::class )->get_string( 'cost_source' )
				);
			}
		);

		$container->bind(
			SchemaMigrator::class,
			function ( ContainerInterface $c ): SchemaMigrator {
				global $wpdb;

				return new SchemaMigrator( $wpdb, $c->get( SettingsInterface::class ) );
			}
		);

		$container->bind(
			OrderStatsRepositoryInterface::class,
			function (): OrderStatsRepositoryInterface {
				global $wpdb;

				return new WcOrderStatsRepository( $wpdb, $this->timezone, wc_get_price_decimals() );
			}
		);

		$container->bind(
			CouponStatsRepositoryInterface::class,
			function ( ContainerInterface $c ): CouponStatsRepositoryInterface {
				global $wpdb;

				return new WpCouponStatsRepository( $wpdb, $c->get( SchemaMigrator::class ), $this->timezone );
			}
		);

		$container->bind(
			AggregationService::class,
			static fn ( ContainerInterface $c ): AggregationService => new AggregationService(
				$c->get( OrderStatsRepositoryInterface::class ),
				$c->get( CouponStatsRepositoryInterface::class ),
				$c->get( CostSourceRegistry::class )
			)
		);

		$container->bind(
			Aggregator::class,
			fn ( ContainerInterface $c ): Aggregator => new Aggregator(
				$c->get( AggregationService::class ),
				$c->get( OrderStatsRepositoryInterface::class ),
				$c->get( SettingsInterface::class ),
				$c->get( ClockInterface::class ),
				$this->timezone
			)
		);

		$container->bind(
			Activator::class,
			static fn ( ContainerInterface $c ): Activator => new Activator(
				$c->get( SchemaMigrator::class ),
				$c->get( Aggregator::class )
			)
		);

		$container->bind(
			CatalogRepositoryInterface::class,
			static fn (): CatalogRepositoryInterface => new WcCatalogRepository(
				get_woocommerce_currency(),
				wc_get_price_decimals()
			)
		);

		$container->bind(
			IntegrationRegistry::class,
			static fn (): IntegrationRegistry => new IntegrationRegistry(
				array(
					new WjecfIntegration(),
					new YithPointsIntegration(),
				)
			)
		);

		$container->bind( ConfigurationAuditor::class, static fn (): ConfigurationAuditor => new ConfigurationAuditor() );

		$container->bind( ScopeIndex::class, static fn (): ScopeIndex => new ScopeIndex() );

		$container->bind(
			OverlapDetector::class,
			static fn ( ContainerInterface $c ): OverlapDetector => new OverlapDetector(
				$c->get( StatusResolver::class ),
				$c->get( ScopeIndex::class )
			)
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
	public function boot( ContainerInterface $container ): void {
		/*
		 * Support for other coupon plugins, attached before anything reads a
		 * coupon. Each stays inert unless its plugin is actually running.
		 */
		$container->get( IntegrationRegistry::class )->register_all();

		/*
		 * An order reaching a state whose revenue counts, or leaving one, means
		 * its day's figures are stale. The day is queued rather than recomputed
		 * inline: a shop owner marking twenty orders complete should not wait for
		 * twenty aggregations, and §8.3's reasoning about background work applies
		 * here just as much.
		 */
		add_action(
			'woocommerce_order_status_changed',
			static function ( $order_id ) use ( $container ): void {
				$container->get( Aggregator::class )->queue_order( (int) $order_id );
			}
		);

		add_action(
			'woocommerce_order_refunded',
			static function ( $order_id ) use ( $container ): void {
				$container->get( Aggregator::class )->queue_order( (int) $order_id );
			}
		);

		add_action(
			Aggregator::AGGREGATE_DAY,
			static function ( $day ) use ( $container ): void {
				$container->get( Aggregator::class )->run_day( (string) $day );
			}
		);

		add_action(
			Aggregator::BACKFILL_STEP,
			static function () use ( $container ): void {
				$container->get( Aggregator::class )->run_backfill_step();
			}
		);
	}
}
