<?php
/**
 * Base container exception.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Container\Exception;

use RuntimeException;

/**
 * Every failure raised by the container derives from this, so callers can catch
 * the whole family without naming each case.
 */
class ContainerException extends RuntimeException {}
