<?php
/**
 * System clock unit tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Domain\Clock;

use DateTimeImmutable;
use DateTimeZone;
use DFX\CouponAAW\Domain\Clock\SystemClock;
use PHPUnit\Framework\TestCase;

/**
 * The one implementation that reads the real time.
 *
 * It takes its timezone as a constructor argument rather than calling
 * `wp_timezone()` itself, which is what keeps the whole Clock namespace free of
 * WordPress — the provider supplies the site's zone at wiring time.
 */
final class SystemClockTest extends TestCase {

	/**
	 * The clock reports in the timezone it was given, not the machine's.
	 */
	public function test_it_reports_in_the_timezone_it_was_given(): void {
		$clock = new SystemClock( new DateTimeZone( 'Europe/Madrid' ) );

		$this->assertSame( 'Europe/Madrid', $clock->now()->getTimezone()->getName() );
	}

	/**
	 * It reports the actual current time.
	 */
	public function test_it_reports_the_current_instant(): void {
		$zone = new DateTimeZone( 'UTC' );

		$reported  = ( new SystemClock( $zone ) )->now();
		$reference = new DateTimeImmutable( 'now', $zone );

		$this->assertLessThan(
			5,
			abs( $reference->getTimestamp() - $reported->getTimestamp() ),
			'The system clock should track real time.'
		);
	}

	/**
	 * Two consecutive readings move forward, never backward — this is the one
	 * clock in the codebase that is allowed to change between calls.
	 */
	public function test_consecutive_readings_do_not_go_backwards(): void {
		$clock = new SystemClock( new DateTimeZone( 'UTC' ) );

		$first  = $clock->now();
		$second = $clock->now();

		$this->assertGreaterThanOrEqual( $first->getTimestamp(), $second->getTimestamp() );
	}
}
