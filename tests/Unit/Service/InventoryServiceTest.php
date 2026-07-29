<?php
/**
 * Inventory service unit tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Service;

use DFX\CouponAAW\Domain\Coupon\CouponScope;
use DFX\CouponAAW\Domain\Coupon\CouponSnapshot;
use DFX\CouponAAW\Domain\Coupon\CouponStatus;
use DFX\CouponAAW\Domain\Coupon\OrphanDetector;
use DFX\CouponAAW\Domain\Coupon\OrphanReason;
use DFX\CouponAAW\Domain\Coupon\StatusResolver;
use DFX\CouponAAW\Domain\Overlap\OverlapDetector;
use DFX\CouponAAW\Domain\Overlap\OverlapSeverity;
use DFX\CouponAAW\Domain\Overlap\ScopeIndex;
use DFX\CouponAAW\Service\InventoryService;
use DFX\CouponAAW\Tests\Fixtures\CouponSnapshotBuilder;
use DFX\CouponAAW\Tests\Fixtures\FrozenClock;
use DFX\CouponAAW\Tests\Fixtures\InMemoryCouponRepository;
use PHPUnit\Framework\TestCase;

/**
 * Orchestration only: the service coordinates the repository and the domain and
 * calculates nothing itself (§5). What it must get right is that every coupon
 * is judged against the whole inventory, not against itself.
 */
final class InventoryServiceTest extends TestCase {

	/**
	 * Build a service over a fixed set of coupons, with the clock at 2026-07-28.
	 *
	 * @param list<CouponSnapshot> $coupons The stored coupons.
	 */
	private function service( array $coupons ): InventoryService {
		$clock = FrozenClock::at( '2026-07-28' );

		return new InventoryService(
			new InMemoryCouponRepository( $coupons ),
			new StatusResolver( $clock ),
			new OrphanDetector( new StatusResolver( $clock ), $clock ),
			new OverlapDetector( new StatusResolver( $clock ), new ScopeIndex() )
		);
	}

	/**
	 * An empty store produces an empty inventory rather than an error.
	 */
	public function test_an_empty_store_has_an_empty_inventory(): void {
		$service = $this->service( array() );

		$inventory = $service->build();

		$this->assertSame( array(), $inventory->entries );
		$this->assertSame( 0, $inventory->summary->total );
	}

	/**
	 * Each entry carries the coupon and its resolved status.
	 */
	public function test_every_entry_carries_its_resolved_status(): void {
		$service = $this->service(
			array(
				CouponSnapshotBuilder::make()->with_id( 1 )->with_code( 'live' )->expiring( '2026-12-01' )->build(),
				CouponSnapshotBuilder::make()->with_id( 2 )->with_code( 'gone' )->expiring( '2026-01-01' )->build(),
			)
		);

		$entries = $service->build()->entries;

		$this->assertCount( 2, $entries );
		$this->assertSame( 'live', $entries[0]->coupon->code );
		$this->assertSame( CouponStatus::ACTIVE, $entries[0]->status );
		$this->assertSame( CouponStatus::EXPIRED, $entries[1]->status );
	}

	/**
	 * Orphan reasons come through on the entry.
	 */
	public function test_entries_carry_their_orphan_reasons(): void {
		$service = $this->service(
			array( CouponSnapshotBuilder::make()->created( '2026-07-01' )->last_used( '2026-07-20' )->build() )
		);

		$entry = $service->build()->entries[0];

		$this->assertSame( array( OrphanReason::NO_EXPIRY_DATE ), $entry->orphan_reasons );
		$this->assertTrue( $entry->is_orphan() );
	}

	/**
	 * A healthy coupon is not flagged.
	 */
	public function test_a_healthy_coupon_is_not_flagged(): void {
		$service = $this->service(
			array(
				CouponSnapshotBuilder::make()
					->expiring( '2026-12-01' )
					->created( '2026-07-01' )
					->last_used( '2026-07-20' )
					->build(),
			)
		);

		$this->assertFalse( $service->build()->entries[0]->is_orphan() );
	}

	/**
	 * Dead-campaign detection needs every other coupon to compare against. If
	 * the service judged each coupon on its own, this rule could never fire —
	 * which is the single thing most worth pinning down about this class.
	 */
	public function test_each_coupon_is_judged_against_the_whole_inventory(): void {
		$service = $this->service(
			array(
				CouponSnapshotBuilder::make()
					->with_id( 1 )->with_code( 'SUMMER24-A' )
					->created( '2026-07-01' )->last_used( '2026-07-20' )->expiring( '2026-12-01' )
					->build(),
				CouponSnapshotBuilder::make()
					->with_id( 2 )->with_code( 'SUMMER24-B' )
					->created( '2026-07-01' )->last_used( '2026-07-20' )->expiring( '2026-06-01' )
					->build(),
			)
		);

		$this->assertSame(
			array( OrphanReason::DEAD_CAMPAIGN ),
			$service->build()->entries[0]->orphan_reasons
		);
	}

	/**
	 * The summary counts every status separately.
	 */
	public function test_the_summary_counts_coupons_by_status(): void {
		$service = $this->service(
			array(
				CouponSnapshotBuilder::make()->with_id( 1 )->with_code( 'a' )->expiring( '2026-12-01' )->build(),
				CouponSnapshotBuilder::make()->with_id( 2 )->with_code( 'b' )->expiring( '2026-12-01' )->build(),
				CouponSnapshotBuilder::make()->with_id( 3 )->with_code( 'c' )->expiring( '2026-01-01' )->build(),
				CouponSnapshotBuilder::make()->with_id( 4 )->with_code( 'd' )->unpublished()->build(),
			)
		);

		$summary = $service->build()->summary;

		$this->assertSame( 4, $summary->total );
		$this->assertSame( 2, $summary->of( CouponStatus::ACTIVE ) );
		$this->assertSame( 1, $summary->of( CouponStatus::EXPIRED ) );
		$this->assertSame( 1, $summary->of( CouponStatus::INACTIVE ) );
		$this->assertSame( 0, $summary->of( CouponStatus::EXHAUSTED ) );
	}

	/**
	 * The summary counts orphans.
	 */
	public function test_the_summary_counts_orphans(): void {
		$service = $this->service(
			array(
				CouponSnapshotBuilder::make()->with_id( 1 )->with_code( 'forever' )->build(),
				CouponSnapshotBuilder::make()
					->with_id( 2 )->with_code( 'fine' )
					->expiring( '2026-12-01' )->created( '2026-07-01' )->last_used( '2026-07-20' )
					->build(),
			)
		);

		$this->assertSame( 1, $service->build()->summary->orphans );
	}

	/**
	 * A live coupon with no restrictions applies to the entire catalogue, which
	 * §8.2 calls the most useful finding the inventory produces. The summary
	 * counts them, and counts only the live ones — an expired coupon that would
	 * have applied to everything is not applying to anything.
	 */
	public function test_the_summary_counts_live_unrestricted_coupons(): void {
		$service = $this->service(
			array(
				CouponSnapshotBuilder::make()->with_id( 1 )->with_code( 'everything' )->expiring( '2026-12-01' )->build(),
				CouponSnapshotBuilder::make()
					->with_id( 2 )->with_code( 'narrow' )->expiring( '2026-12-01' )
					->with_scope( new CouponScope( included_products: array( 10 ) ) )
					->build(),
				CouponSnapshotBuilder::make()->with_id( 3 )->with_code( 'dead' )->expiring( '2026-01-01' )->build(),
			)
		);

		$this->assertSame( 1, $service->build()->summary->unrestricted );
	}

	/**
	 * The repository is read once per build, not once per coupon. With the
	 * repository hitting a database and the dead-campaign rule needing the full
	 * set, re-reading per coupon would turn a page load into N queries.
	 */
	public function test_it_reads_the_repository_once(): void {
		$repository = new class( array( CouponSnapshotBuilder::make()->build() ) ) extends InMemoryCouponRepository {

			/**
			 * How many times all() was called.
			 *
			 * @var int
			 */
			public int $reads = 0;

			/**
			 * Count the read.
			 *
			 * @return list<CouponSnapshot>
			 */
			public function all(): array {
				++$this->reads;

				return parent::all();
			}
		};

		$clock = FrozenClock::at( '2026-07-28' );

		$service = new InventoryService(
			$repository,
			new StatusResolver( $clock ),
			new OrphanDetector( new StatusResolver( $clock ), $clock ),
			new OverlapDetector( new StatusResolver( $clock ), new ScopeIndex() )
		);

		$service->build();

		$this->assertSame( 1, $repository->reads );
	}

	/**
	 * Collisions reach the entries of both coupons involved, so a screen can
	 * show the finding against whichever row the user is looking at.
	 */
	public function test_an_overlap_reaches_both_coupons_involved(): void {
		$service = $this->service(
			array(
				$this->healthy( 1, 'alpha' ),
				$this->healthy( 2, 'beta' ),
			)
		);

		$inventory = $service->build();

		$this->assertCount( 1, $inventory->overlaps ?? array() );
		$this->assertCount( 1, $inventory->entries[0]->overlaps );
		$this->assertCount( 1, $inventory->entries[1]->overlaps );
		$this->assertSame( 1, $inventory->summary->overlaps );
	}

	/**
	 * A coupon in several collisions reports the worst of them, since that is
	 * the one worth acting on first.
	 */
	public function test_a_coupon_reports_its_worst_collision(): void {
		// The third coupon opens only after the first two have expired, so it
		// collides with both on paper and with neither in a basket.
		$service = $this->service(
			array(
				$this->healthy( 1, 'alpha' ),
				$this->healthy( 2, 'beta' ),
				CouponSnapshotBuilder::make()
					->with_id( 3 )->with_code( 'much-later' )
					->created( '2026-07-01' )->last_used( '2026-07-20' )
					->starting( '2027-01-01' )
					->build(),
			)
		);

		$entries = $service->build()->entries;

		$this->assertCount( 2, $entries[0]->overlaps );
		$this->assertSame( OverlapSeverity::MEDIUM, $entries[0]->worst_overlap() );
		$this->assertSame( OverlapSeverity::LOW, $entries[2]->worst_overlap() );
	}

	/**
	 * A coupon in no collision reports none.
	 */
	public function test_a_coupon_without_collisions_reports_none(): void {
		$service = $this->service( array( $this->healthy( 1, 'alone' ) ) );

		$this->assertNull( $service->build()->entries[0]->worst_overlap() );
		$this->assertSame( 0, $service->build()->summary->overlaps );
	}

	/**
	 * Past the limit, the check is reported as not run rather than run badly.
	 * §8.3 wants this work in the background for large inventories; until that
	 * exists, saying nothing is honest and hanging the screen is not.
	 */
	public function test_overlap_detection_is_skipped_on_a_large_inventory(): void {
		$coupons = array();

		for ( $i = 1; $i <= OverlapDetector::SYNCHRONOUS_LIMIT + 1; $i++ ) {
			$coupons[] = $this->healthy( $i, 'c' . $i );
		}

		$inventory = $this->service( $coupons )->build();

		$this->assertFalse( $inventory->overlaps_were_checked() );
		$this->assertNull( $inventory->overlaps );
		$this->assertNull( $inventory->summary->overlaps );
		$this->assertSame( array(), $inventory->entries[0]->overlaps );
	}

	/**
	 * At the limit it still runs, so the boundary is not off by one.
	 */
	public function test_overlap_detection_runs_at_the_limit(): void {
		$coupons = array();

		for ( $i = 1; $i <= OverlapDetector::SYNCHRONOUS_LIMIT; $i++ ) {
			$coupons[] = $this->healthy( $i, 'c' . $i );
		}

		$this->assertTrue( $this->service( $coupons )->build()->overlaps_were_checked() );
	}

	/**
	 * A live coupon that is not flagged for anything else.
	 *
	 * @param int    $id   Post ID.
	 * @param string $code Coupon code.
	 */
	private function healthy( int $id, string $code ): CouponSnapshot {
		return CouponSnapshotBuilder::make()
			->with_id( $id )
			->with_code( $code )
			->created( '2026-07-01' )
			->last_used( '2026-07-20' )
			->expiring( '2026-12-01' )
			->build();
	}
}
