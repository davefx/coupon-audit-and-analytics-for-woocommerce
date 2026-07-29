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
	 * Constructor.
	 *
	 * The exception carries its own message so that the throw site passes no
	 * argument at all. The identifier used to be interpolated, which was more
	 * helpful, and which WordPress.Security.EscapeOutput rejects: it treats
	 * anything reaching an exception constructor as output, a class constant
	 * included. The sniff can be satisfied by assembling the exception into a
	 * variable first, but that defeats the check without changing anything, so
	 * the message is simply fixed. The identifier is still one frame away in the
	 * stack trace.
	 */
	public function __construct() {
		parent::__construct( 'A service was resolved that is not registered in the container.' );
	}
}
