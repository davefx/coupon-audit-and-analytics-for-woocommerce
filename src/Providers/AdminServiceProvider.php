<?php
/**
 * Admin bindings and hooks.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Providers;

use DFX\CouponAAW\Admin\AssetLoader;
use DFX\CouponAAW\Admin\CouponTermsFormatter;
use DFX\CouponAAW\Admin\CouponEditorNotices;
use DFX\CouponAAW\Admin\InventoryListTable;
use DFX\CouponAAW\Admin\InventoryPage;
use DFX\CouponAAW\Admin\MarginListTable;
use DFX\CouponAAW\Admin\MarginPage;
use DFX\CouponAAW\Admin\MenuRegistrar;
use DFX\CouponAAW\Admin\SettingsPage;
use DFX\CouponAAW\Container\ContainerInterface;
use DFX\CouponAAW\Container\ServiceProviderInterface;
use DFX\CouponAAW\Domain\Clock\ClockInterface;
use DFX\CouponAAW\Domain\Coupon\OrphanDetector;
use DFX\CouponAAW\Domain\Coupon\StatusResolver;
use DFX\CouponAAW\Domain\Overlap\OverlapDetector;
use DFX\CouponAAW\Repository\CouponRepositoryInterface;
use DFX\CouponAAW\Catalog\CatalogRepositoryInterface;
use DFX\CouponAAW\Cost\CostSourceRegistry;
use DFX\CouponAAW\Domain\Coupon\ConfigurationAuditor;
use DFX\CouponAAW\Install\Aggregator;
use DFX\CouponAAW\Repository\CouponStatsRepositoryInterface;
use DFX\CouponAAW\Service\InventoryService;
use DFX\CouponAAW\Service\MarginService;
use DFX\CouponAAW\Service\PrePublishValidator;
use DFX\CouponAAW\Support\PluginContext;
use DFX\CouponAAW\Support\SettingsInterface;

/**
 * Wires the admin screens.
 *
 * Everything is registered but nothing is built: the screens are resolved
 * inside the hook callbacks, so a front-end request pays for none of it.
 */
final class AdminServiceProvider implements ServiceProviderInterface {

	/**
	 * How far back the margin screen looks.
	 *
	 * Filtered here rather than inside the service, so that the service keeps no
	 * opinion about WordPress and stays testable without it.
	 *
	 * A window of less than a day is refused: it would produce a period ending
	 * before it began, and a screen full of nothing. A filter is allowed to be
	 * wrong without taking the screen down with it.
	 */
	private static function margin_window_days(): int {
		/**
		 * Filters how many days of history the margin screen covers.
		 *
		 * Thirty days is a default rather than a limit, and this filter is how a
		 * different one is chosen — from a reporting period, from another plugin,
		 * or from a line in a theme's functions.php.
		 *
		 * @since 0.3.0
		 *
		 * @param int $days How many days back to look. At least 1.
		 */
		$days = apply_filters( 'dfxcaaw_margin_window_days', MarginService::WINDOW_DAYS );

		if ( ! is_numeric( $days ) || (int) $days < 1 ) {
			return MarginService::WINDOW_DAYS;
		}

		return (int) $days;
	}

	/**
	 * Register bindings.
	 *
	 * @param ContainerInterface $container Service container.
	 */
	public function register( ContainerInterface $container ): void {
		$container->bind(
			InventoryService::class,
			static fn ( ContainerInterface $c ): InventoryService => new InventoryService(
				$c->get( CouponRepositoryInterface::class ),
				$c->get( StatusResolver::class ),
				$c->get( OrphanDetector::class ),
				$c->get( OverlapDetector::class ),
				$c->get( ConfigurationAuditor::class ),
				$c->get( CatalogRepositoryInterface::class )
			)
		);

		$container->bind(
			CouponTermsFormatter::class,
			static fn ( ContainerInterface $c ): CouponTermsFormatter => new CouponTermsFormatter(
				$c->get( CatalogRepositoryInterface::class )
			)
		);

		$container->bind(
			InventoryListTable::class,
			static function ( ContainerInterface $c ): InventoryListTable {
				// WP_List_Table lives in wp-admin and is not autoloaded. Requiring
				// it here rather than at the top of the class file means the class
				// is only ever resolved on a screen that has already loaded it.
				if ( ! class_exists( 'WP_List_Table' ) ) {
					require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
				}

				return new InventoryListTable( $c->get( CouponTermsFormatter::class ) );
			}
		);

		$container->bind(
			InventoryPage::class,
			static fn ( ContainerInterface $c ): InventoryPage => new InventoryPage(
				$c->get( InventoryService::class ),
				$c->get( InventoryListTable::class )
			)
		);

		$container->bind(
			MarginService::class,
			static fn ( ContainerInterface $c ): MarginService => new MarginService(
				$c->get( CouponStatsRepositoryInterface::class ),
				$c->get( CouponRepositoryInterface::class ),
				$c->get( ClockInterface::class ),
				self::margin_window_days()
			)
		);

		$container->bind(
			MarginListTable::class,
			static function (): MarginListTable {
				if ( ! class_exists( 'WP_List_Table' ) ) {
					require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
				}

				return new MarginListTable();
			}
		);

		$container->bind(
			MarginPage::class,
			static fn ( ContainerInterface $c ): MarginPage => new MarginPage(
				$c->get( MarginService::class ),
				$c->get( MarginListTable::class ),
				$c->get( Aggregator::class )
			)
		);

		$container->bind(
			SettingsPage::class,
			static fn ( ContainerInterface $c ): SettingsPage => new SettingsPage(
				$c->get( SettingsInterface::class ),
				$c->get( CostSourceRegistry::class )
			)
		);

		$container->bind(
			MenuRegistrar::class,
			static fn ( ContainerInterface $c ): MenuRegistrar => new MenuRegistrar(
				$c->get( InventoryPage::class ),
				$c->get( MarginPage::class ),
				$c->get( SettingsPage::class )
			)
		);

		$container->bind(
			PrePublishValidator::class,
			static fn ( ContainerInterface $c ): PrePublishValidator => new PrePublishValidator(
				$c->get( CouponRepositoryInterface::class ),
				$c->get( OverlapDetector::class )
			)
		);

		$container->bind(
			CouponEditorNotices::class,
			static fn ( ContainerInterface $c ): CouponEditorNotices => new CouponEditorNotices(
				$c->get( PrePublishValidator::class ),
				$c->get( CouponRepositoryInterface::class )
			)
		);

		$container->bind(
			AssetLoader::class,
			static fn ( ContainerInterface $c ): AssetLoader => new AssetLoader(
				$c->get( PluginContext::class )
			)
		);
	}

	/**
	 * Hook the screens into WordPress.
	 *
	 * @param ContainerInterface $container Service container.
	 */
	public function boot( ContainerInterface $container ): void {
		if ( ! is_admin() ) {
			return;
		}

		add_action(
			'admin_menu',
			static function () use ( $container ): void {
				$container->get( MenuRegistrar::class )->register();
			}
		);

		add_action(
			'admin_notices',
			static function () use ( $container ): void {
				$container->get( CouponEditorNotices::class )->render();
			}
		);

		add_action(
			'admin_enqueue_scripts',
			static function ( $hook_suffix ) use ( $container ): void {
				$container->get( AssetLoader::class )->enqueue( (string) $hook_suffix );
			}
		);
	}
}
