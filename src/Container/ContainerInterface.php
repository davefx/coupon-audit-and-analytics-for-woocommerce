<?php
/**
 * Service container contract.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Container;

use DFX\CouponAAW\Container\Exception\CircularDependencyException;
use DFX\CouponAAW\Container\Exception\ServiceNotFoundException;

/**
 * Registers and resolves services.
 *
 * Consumers depend on this interface rather than on the concrete container, so
 * a test can hand any implementation to the code under test.
 */
interface ContainerInterface {

	/**
	 * Register a shared service.
	 *
	 * The factory runs at most once; the result is cached and returned to every
	 * later caller. Binding an identifier that is already bound replaces the
	 * previous definition and discards any instance already resolved from it.
	 *
	 * @param string                             $id      Service identifier, conventionally a class or interface name.
	 * @param callable(ContainerInterface):mixed $factory Builds the service.
	 */
	public function bind( string $id, callable $factory ): void;

	/**
	 * Register a service that is rebuilt on every resolution.
	 *
	 * @param string                             $id      Service identifier.
	 * @param callable(ContainerInterface):mixed $factory Builds the service.
	 */
	public function factory( string $id, callable $factory ): void;

	/**
	 * Register an already-built object.
	 *
	 * This is the seam tests use to substitute a double for a real collaborator.
	 *
	 * @param string $id       Service identifier.
	 * @param object $instance The object to hand back on resolution.
	 */
	public function instance( string $id, object $instance ): void;

	/**
	 * Whether an identifier can be resolved.
	 *
	 * @param string $id Service identifier.
	 */
	public function has( string $id ): bool;

	/**
	 * Resolve a service.
	 *
	 * @param string $id Service identifier.
	 *
	 * @throws ServiceNotFoundException     When the identifier was never registered.
	 * @throws CircularDependencyException  When resolving the identifier requires itself.
	 */
	public function get( string $id ): mixed;
}
