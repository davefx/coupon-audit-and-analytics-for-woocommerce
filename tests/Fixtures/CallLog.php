<?php
/**
 * Shared call recorder for collaboration tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Fixtures;

/**
 * Collects entries in call order.
 *
 * Passed around as an object rather than as a by-reference array: reference
 * parameters cannot be promoted portably, and a shared object reads better at
 * the assertion site anyway.
 */
final class CallLog {

	/**
	 * Recorded entries, in order.
	 *
	 * @var list<mixed>
	 */
	private array $entries = array();

	/**
	 * Record an entry.
	 *
	 * @param mixed $entry Whatever the test wants to observe.
	 */
	public function record( mixed $entry ): void {
		$this->entries[] = $entry;
	}

	/**
	 * Everything recorded so far, in order.
	 *
	 * @return list<mixed>
	 */
	public function entries(): array {
		return $this->entries;
	}
}
