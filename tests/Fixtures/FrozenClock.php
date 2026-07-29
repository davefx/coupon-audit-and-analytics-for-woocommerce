<?php
/**
 * A clock that does not move.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Fixtures;

use DateTimeImmutable;
use DateTimeZone;
use DFX\CouponAAW\Domain\Clock\ClockInterface;

/**
 * Every test that touches a date uses this. Nothing in the domain reads the
 * real time, so boundary cases like "expires today" can be written as plain
 * assertions instead of as something that passes until the clock rolls over.
 */
final class FrozenClock implements ClockInterface {

	/**
	 * Constructor.
	 *
	 * @param DateTimeImmutable $now The instant this clock always reports.
	 */
	public function __construct( private readonly DateTimeImmutable $now ) {}

	/**
	 * Build a clock frozen at a UTC instant.
	 *
	 * @param string $time Anything DateTimeImmutable understands.
	 */
	public static function at( string $time ): self {
		return new self( new DateTimeImmutable( $time, new DateTimeZone( 'UTC' ) ) );
	}

	/**
	 * The frozen instant.
	 */
	public function now(): DateTimeImmutable {
		return $this->now;
	}
}
