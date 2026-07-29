<?php
/**
 * Test collaborator with no dependencies.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Fixtures\Container;

/**
 * A trivial service used to assert container resolution and caching.
 */
final class DummyService {

	/**
	 * Constructor.
	 *
	 * @param string $name Arbitrary label, so distinct instances can be told apart.
	 */
	public function __construct( public readonly string $name = 'dummy' ) {}
}
