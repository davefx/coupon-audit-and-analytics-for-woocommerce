<?php
/**
 * Inventory entry tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Service;

use DFX\CouponAAW\Domain\Coupon\ConfigurationIssue;
use DFX\CouponAAW\Domain\Coupon\CouponStatus;
use DFX\CouponAAW\Domain\Coupon\OrphanReason;
use DFX\CouponAAW\Domain\Overlap\Overlap;
use DFX\CouponAAW\Domain\Overlap\OverlapSeverity;
use DFX\CouponAAW\Service\InventoryEntry;
use DFX\CouponAAW\Tests\Fixtures\CouponSnapshotBuilder;
use PHPUnit\Framework\TestCase;

/**
 * One row of the overview.
 *
 * "Does this coupon need attention" drives the summary tile and the row's own
 * highlighting, and it is a finding of *any* kind — a relic, a bad setting, or a
 * collision. A version that only counted one of the three would leave rows
 * looking clean while showing a warning in the column beside them.
 */
final class InventoryEntryTest extends TestCase {

	/**
	 * An entry with whichever findings are given.
	 *
	 * @param list<OrphanReason>       $orphan_reasons Grounds for being a relic.
	 * @param list<Overlap>            $overlaps       Collisions it takes part in.
	 * @param list<ConfigurationIssue> $issues         Faults in its own terms.
	 */
	private function entry( array $orphan_reasons = array(), array $overlaps = array(), array $issues = array() ): InventoryEntry {
		return new InventoryEntry(
			CouponSnapshotBuilder::make()->build(),
			CouponStatus::ACTIVE,
			$orphan_reasons,
			$overlaps,
			$issues
		);
	}

	/**
	 * A collision between two coupons.
	 */
	private function overlap(): Overlap {
		return Overlap::between(
			CouponSnapshotBuilder::make()->with_id( 1 )->with_code( 'one' )->build(),
			CouponSnapshotBuilder::make()->with_id( 2 )->with_code( 'two' )->build(),
			OverlapSeverity::LOW
		);
	}

	/**
	 * A coupon nothing was found against needs no attention.
	 */
	public function test_a_clean_coupon_has_no_findings(): void {
		$entry = $this->entry();

		$this->assertFalse( $entry->has_findings() );
		$this->assertFalse( $entry->is_orphan() );
	}

	/**
	 * Being a relic is a finding.
	 */
	public function test_an_orphan_has_findings(): void {
		$entry = $this->entry( orphan_reasons: array( OrphanReason::NO_EXPIRY_DATE ) );

		$this->assertTrue( $entry->is_orphan() );
		$this->assertTrue( $entry->has_findings() );
	}

	/**
	 * So is a collision, on its own.
	 */
	public function test_an_overlap_alone_is_a_finding(): void {
		$entry = $this->entry( overlaps: array( $this->overlap() ) );

		$this->assertFalse( $entry->is_orphan() );
		$this->assertTrue( $entry->has_findings() );
	}

	/**
	 * And so is a fault in the coupon's own terms, on its own.
	 */
	public function test_a_configuration_issue_alone_is_a_finding(): void {
		$entry = $this->entry(
			issues: array( ConfigurationIssue::DISCOUNT_EXCEEDS_MINIMUM_SPEND )
		);

		$this->assertFalse( $entry->is_orphan() );
		$this->assertTrue( $entry->has_findings() );
	}
}
