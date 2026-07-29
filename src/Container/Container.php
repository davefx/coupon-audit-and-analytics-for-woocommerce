<?php
/**
 * Service container.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Container;

use DFX\CouponAAW\Container\Exception\CircularDependencyException;
use DFX\CouponAAW\Container\Exception\ServiceNotFoundException;

/**
 * The second and last singleton in the codebase (§3.3).
 *
 * The constructor is deliberately public. `get_instance()` provides the single
 * point of access the plugin boundary needs, while tests build their own
 * container and never touch the static one — which is what stops the singleton
 * from leaking state between tests.
 */
final class Container implements ContainerInterface {

	/**
	 * The canonical container, created on first access.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Registered factories, keyed by identifier.
	 *
	 * @var array<string, callable(ContainerInterface):mixed>
	 */
	private array $factories = array();

	/**
	 * Whether each registered factory caches its result, keyed by identifier.
	 *
	 * @var array<string, bool>
	 */
	private array $shared = array();

	/**
	 * Resolved values, keyed by identifier.
	 *
	 * @var array<string, mixed>
	 */
	private array $resolved = array();

	/**
	 * Identifiers currently being resolved, outermost first. Used to spot cycles.
	 *
	 * @var list<string>
	 */
	private array $resolving = array();

	/**
	 * The canonical container.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register a shared service.
	 *
	 * @param string                             $id      Service identifier.
	 * @param callable(ContainerInterface):mixed $factory Builds the service.
	 */
	public function bind( string $id, callable $factory ): void {
		$this->register( $id, $factory, true );
	}

	/**
	 * Register a service that is rebuilt on every resolution.
	 *
	 * @param string                             $id      Service identifier.
	 * @param callable(ContainerInterface):mixed $factory Builds the service.
	 */
	public function factory( string $id, callable $factory ): void {
		$this->register( $id, $factory, false );
	}

	/**
	 * Register an already-built object.
	 *
	 * @param string $id       Service identifier.
	 * @param object $instance The object to hand back on resolution.
	 */
	public function instance( string $id, object $instance ): void {
		unset( $this->factories[ $id ], $this->shared[ $id ] );

		$this->resolved[ $id ] = $instance;
	}

	/**
	 * Whether an identifier can be resolved.
	 *
	 * @param string $id Service identifier.
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] ) || array_key_exists( $id, $this->resolved );
	}

	/**
	 * Resolve a service.
	 *
	 * @param string $id Service identifier.
	 *
	 * @throws ServiceNotFoundException    When the identifier was never registered.
	 * @throws CircularDependencyException When resolving the identifier requires itself.
	 */
	public function get( string $id ): mixed {
		if ( array_key_exists( $id, $this->resolved ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new ServiceNotFoundException();
		}

		if ( in_array( $id, $this->resolving, true ) ) {
			throw new CircularDependencyException();
		}

		$this->resolving[] = $id;

		try {
			$value = ( $this->factories[ $id ] )( $this );
		} finally {
			array_pop( $this->resolving );
		}

		if ( true === $this->shared[ $id ] ) {
			$this->resolved[ $id ] = $value;
		}

		return $value;
	}

	/**
	 * Store a factory, replacing whatever was registered under the same
	 * identifier — including an instance already resolved from it.
	 *
	 * @param string                             $id      Service identifier.
	 * @param callable(ContainerInterface):mixed $factory Builds the service.
	 * @param bool                               $shared  Whether to cache the result.
	 */
	private function register( string $id, callable $factory, bool $shared ): void {
		unset( $this->resolved[ $id ] );

		$this->factories[ $id ] = $factory;
		$this->shared[ $id ]    = $shared;
	}
}
