<?php
/**
 * Feature gate unit tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Licensing;

use DFX\CouponAAW\Licensing\Feature;
use DFX\CouponAAW\Licensing\LocalFeatureGate;
use PHPUnit\Framework\TestCase;

/**
 * The first release ships no SDK, so the gate is a lookup (§11).
 */
final class LocalFeatureGateTest extends TestCase {

	/**
	 * Everything the free tier promises is allowed.
	 *
	 * @dataProvider provide_free_features
	 *
	 * @param Feature $feature A feature that must be free.
	 */
	public function test_it_allows_the_free_features( Feature $feature ): void {
		$this->assertTrue( ( new LocalFeatureGate() )->allows( $feature ) );
	}

	/**
	 * What the free tier covers.
	 *
	 * @return array<string, array{Feature}>
	 */
	public static function provide_free_features(): array {
		return array(
			'inventory'          => array( Feature::INVENTORY ),
			'thirty-day margin'  => array( Feature::GROSS_MARGIN_30D ),
			'pre-publish checks' => array( Feature::PRE_PUBLISH_BASIC ),
		);
	}

	/**
	 * Everything else is closed.
	 *
	 * @dataProvider provide_paid_features
	 *
	 * @param Feature $feature A feature that must be paid for.
	 */
	public function test_it_refuses_the_paid_features( Feature $feature ): void {
		$this->assertFalse( ( new LocalFeatureGate() )->allows( $feature ) );
	}

	/**
	 * What the free tier does not cover.
	 *
	 * @return array<string, array{Feature}>
	 */
	public static function provide_paid_features(): array {
		return array(
			'full history'      => array( Feature::FULL_HISTORY ),
			'net margin'        => array( Feature::NET_MARGIN ),
			'customer segments' => array( Feature::CUSTOMER_SEGMENTS ),
			'alerts'            => array( Feature::ALERTS ),
			'export'            => array( Feature::EXPORT ),
		);
	}

	/**
	 * Every feature is decided one way or the other. A case added to the enum
	 * and forgotten here would silently take whatever `default` gives it, and
	 * the wrong default is the one that gives away paid work.
	 */
	public function test_every_feature_has_a_decision(): void {
		$gate = new LocalFeatureGate();
		$free = 0;

		foreach ( Feature::cases() as $feature ) {
			$free += $gate->allows( $feature ) ? 1 : 0;
		}

		$this->assertSame( 3, $free, 'Exactly three features are free; a new one defaults to paid.' );
		$this->assertCount( 8, Feature::cases() );
	}
}
