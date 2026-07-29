<?php
/**
 * Unknown service identifier.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Container\Exception;

/**
 * Raised when an identifier is resolved without ever having been registered.
 *
 * This is always a wiring mistake, never a runtime condition to recover from,
 * which is why the container throws instead of returning null.
 */
final class ServiceNotFoundException extends ContainerException {

	/**
	 * Build the exception for a given identifier.
	 *
	 * @param string $id The identifier that could not be resolved.
	 */
	public static function for_id( string $id ): self {
		return new self( sprintf( 'Service "%s" is not registered in the container.', $id ) );
	}
}
