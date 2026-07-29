<?php
/**
 * Core bindings.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Providers;

use DFX\CouponAAW\Container\ContainerInterface;
use DFX\CouponAAW\Container\ServiceProviderInterface;
use DFX\CouponAAW\Support\PluginContext;

/**
 * Contributes the bindings that every other slice of the plugin depends on.
 *
 * The mandatory test seams of §10.4 — clock, repositories, feature gate,
 * options — are registered here as each is built.
 */
final class CoreServiceProvider implements ServiceProviderInterface {

	/**
	 * Constructor.
	 *
	 * @param PluginContext $context Values supplied by the main plugin file.
	 */
	public function __construct( private readonly PluginContext $context ) {}

	/**
	 * Register bindings.
	 *
	 * @param ContainerInterface $container Service container.
	 */
	public function register( ContainerInterface $container ): void {
		$container->instance( PluginContext::class, $this->context );
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
