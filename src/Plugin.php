<?php
/**
 * Plugin entry point.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW;

use DFX\CouponAAW\Container\Container;
use DFX\CouponAAW\Container\ContainerInterface;
use DFX\CouponAAW\Container\ServiceProviderInterface;
use LogicException;

/**
 * The first and last of the two singletons (§3.3).
 *
 * It holds the container, collects service providers and runs the two-phase
 * boot. It contains no business logic and — deliberately — calls no WordPress
 * function: the main plugin file owns the hook that invokes `boot()`, which is
 * what allows this class to be unit tested without loading WordPress.
 *
 * The constructor is public so tests can build a plugin around their own
 * container instead of the canonical one.
 */
final class Plugin {

	/**
	 * The canonical plugin, created on first access.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Registered providers, in the order they were added.
	 *
	 * @var list<ServiceProviderInterface>
	 */
	private array $providers = array();

	/**
	 * Whether `boot()` has already run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Service container.
	 */
	public function __construct( private readonly ContainerInterface $container ) {}

	/**
	 * The canonical plugin, wired to the canonical container.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self( Container::get_instance() );
		}

		return self::$instance;
	}

	/**
	 * The container this plugin was built with.
	 */
	public function container(): ContainerInterface {
		return $this->container;
	}

	/**
	 * Add a service provider.
	 *
	 * @param ServiceProviderInterface $provider The provider to add.
	 *
	 * @throws LogicException When the plugin has already booted, since the
	 *                        provider would otherwise be silently ignored.
	 */
	public function add_provider( ServiceProviderInterface $provider ): self {
		if ( $this->booted ) {
			throw new LogicException(
				sprintf(
					'Service provider "%s" was added after the plugin booted; it would never run.',
					$provider::class
				)
			);
		}

		$this->providers[] = $provider;

		return $this;
	}

	/**
	 * Register every provider, then boot every provider. Repeated calls do
	 * nothing.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		foreach ( $this->providers as $provider ) {
			$provider->register( $this->container );
		}

		foreach ( $this->providers as $provider ) {
			$provider->boot( $this->container );
		}
	}

	/**
	 * Whether the plugin has booted.
	 */
	public function is_booted(): bool {
		return $this->booted;
	}
}
