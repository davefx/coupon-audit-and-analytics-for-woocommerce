<?php
/**
 * Service provider contract.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Container;

/**
 * A unit of wiring: it teaches the container how to build one slice of the
 * plugin, then hooks that slice into WordPress.
 *
 * The two phases are separate and never interleaved across providers. By the
 * time any `boot()` runs, every provider has registered its bindings, so a
 * provider may safely resolve services contributed by another.
 */
interface ServiceProviderInterface {

	/**
	 * Register bindings. Must not resolve anything and must not touch WordPress.
	 *
	 * @param ContainerInterface $container Service container.
	 */
	public function register( ContainerInterface $container ): void;

	/**
	 * Wire the registered services into WordPress: hooks, filters, routes.
	 *
	 * @param ContainerInterface $container Service container.
	 */
	public function boot( ContainerInterface $container ): void;
}
