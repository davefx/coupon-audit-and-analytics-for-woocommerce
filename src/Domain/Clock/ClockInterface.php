<?php
/**
 * Clock contract.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Clock;

use DateTimeImmutable;

/**
 * The only way any part of this plugin is allowed to learn what time it is.
 *
 * `time()` and `current_time()` are never called directly anywhere in the
 * codebase (§8.1). Without that rule, coupon status is derived from whatever
 * day the test suite happens to run on, and boundary cases — expires today,
 * starts tomorrow — cannot be written at all.
 */
interface ClockInterface {

	/**
	 * The current instant.
	 */
	public function now(): DateTimeImmutable;
}
