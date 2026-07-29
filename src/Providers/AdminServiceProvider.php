<?php
/**
 * Admin bindings and hooks.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Providers;

use DFX\CouponAAW\Admin\AssetLoader;
use DFX\CouponAAW\Admin\InventoryListTable;
use DFX\CouponAAW\Admin\InventoryPage;
use DFX\CouponAAW\Admin\MenuRegistrar;
use DFX\CouponAAW\Container\ContainerInterface;
use DFX\CouponAAW\Container\ServiceProviderInterface;
use DFX\CouponAAW\Domain\Coupon\OrphanDetector;
use DFX\CouponAAW\Domain\Coupon\StatusResolver;
use DFX\CouponAAW\Repository\CouponRepositoryInterface;
use DFX\CouponAAW\Service\InventoryService;
use DFX\CouponAAW\Support\PluginContext;

/**
 * Wires the admin screens.
 *
 * Everything is registered but nothing is built: the screens are resolved
 * inside the hook callbacks, so a front-end request pays for none of it.
 */
final class AdminServiceProvider implements ServiceProviderInterface {

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
				$c->get( OrphanDetector::class )
			)
		);

		$container->bind(
			InventoryListTable::class,
			static function (): InventoryListTable {
				// WP_List_Table lives in wp-admin and is not autoloaded. Requiring
				// it here rather than at the top of the class file means the class
				// is only ever resolved on a screen that has already loaded it.
				if ( ! class_exists( 'WP_List_Table' ) ) {
					require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
				}

				return new InventoryListTable();
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
			MenuRegistrar::class,
			static fn ( ContainerInterface $c ): MenuRegistrar => new MenuRegistrar(
				$c->get( InventoryPage::class )
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
			'admin_enqueue_scripts',
			static function ( $hook_suffix ) use ( $container ): void {
				$container->get( AssetLoader::class )->enqueue( (string) $hook_suffix );
			}
		);
	}
}
