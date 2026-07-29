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
	 * Constructor.
	 *
	 * The resolution chain used to be spelled out in the message. See the note on
	 * ServiceNotFoundException for why it no longer is; the chain is still
	 * readable in the stack trace, one frame per service being resolved.
	 */
	public function __construct() {
		parent::__construct( 'Circular dependency detected: resolving a service required that same service.' );
	}
}
