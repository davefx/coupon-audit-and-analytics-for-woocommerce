<?php
/**
 * Dependency cycle detected during resolution.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Container\Exception;

/**
 * Raised when resolving a service ends up requiring that same service.
 *
 * Left undetected this exhausts the stack and takes the whole request with it,
 * so the container reports the chain instead.
 */
final class CircularDependencyException extends ContainerException {

	/**
	 * Build the exception from the resolution chain that closed the loop.
	 *
	 * @param list<string> $chain Identifiers currently being resolved, outermost first.
	 * @param string       $id    The identifier that reappeared.
	 */
	public static function for_chain( array $chain, string $id ): self {
		$chain[] = $id;

		return new self(
			sprintf( 'Circular dependency detected while resolving: %s.', implode( ' -> ', $chain ) )
		);
	}
}
