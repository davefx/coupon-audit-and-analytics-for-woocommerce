<?php
/**
 * Test collaborator that depends on another service.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Fixtures\Container;

/**
 * A service whose only dependency is resolved through the container.
 */
final class DependentService {

	/**
	 * Constructor.
	 *
	 * @param DummyService $dependency The collaborator resolved from the container.
	 */
	public function __construct( public readonly DummyService $dependency ) {}
}
