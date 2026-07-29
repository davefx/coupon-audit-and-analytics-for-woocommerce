<?php
/**
 * Real-time clock.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Clock;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Reads the real time, in a timezone chosen by whoever wired it up.
 *
 * The timezone arrives through the constructor rather than from a
 * `wp_timezone()` call inside this class. That one decision is what keeps the
 * Clock namespace free of WordPress, and it is the provider's job to pass the
 * site's zone — a store's idea of "today" is its own, not the server's.
 */
final class SystemClock implements ClockInterface {

	/**
	 * Constructor.
	 *
	 * @param DateTimeZone $timezone The zone to report times in.
	 */
	public function __construct( private readonly DateTimeZone $timezone ) {}

	/**
	 * The current instant.
	 */
	public function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', $this->timezone );
	}
}
