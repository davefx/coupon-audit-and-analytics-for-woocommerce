<?php
/**
 * Service provider that records the order in which it is called.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Fixtures;

use DFX\CouponAAW\Container\ContainerInterface;
use DFX\CouponAAW\Container\ServiceProviderInterface;

/**
 * Records "<name>:register" and "<name>:boot" to a shared log, so tests can
 * assert that every provider registers before any provider boots.
 */
final class RecordingServiceProvider implements ServiceProviderInterface {

	/**
	 * Constructor.
	 *
	 * @param string  $name Label written to the log.
	 * @param CallLog $log  Shared call log.
	 */
	public function __construct(
		private readonly string $name,
		private readonly CallLog $log
	) {}

	/**
	 * Record the registration phase.
	 *
	 * @param ContainerInterface $container Service container.
	 */
	public function register( ContainerInterface $container ): void {
		$this->log->record( $this->name . ':register' );
	}

	/**
	 * Record the boot phase.
	 *
	 * @param ContainerInterface $container Service container.
	 */
	public function boot( ContainerInterface $container ): void {
		$this->log->record( $this->name . ':boot' );
	}
}
