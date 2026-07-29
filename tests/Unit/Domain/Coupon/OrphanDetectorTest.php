<?php
/**
 * Orphan detection unit tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Domain\Coupon;

use DFX\CouponAAW\Domain\Coupon\CouponSnapshot;
use DFX\CouponAAW\Domain\Coupon\OrphanDetector;
use DFX\CouponAAW\Domain\Coupon\OrphanReason;
use DFX\CouponAAW\Domain\Coupon\StatusResolver;
use DFX\CouponAAW\Tests\Fixtures\CouponSnapshotBuilder;
use DFX\CouponAAW\Tests\Fixtures\FrozenClock;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Orphans are the relics: coupons still live that nobody meant to leave live
 * (§8.4). This is the finding the audit half of the product exists to surface,
 * and it needs no cost data at all.
 */
final class OrphanDetectorTest extends TestCase {

	/**
	 * A detector whose clock reads 2026-07-28.
	 *
	 * @param int $dormant_after_days Threshold in days.
	 */
	private function detector( int $dormant_after_days = 90 ): OrphanDetector {
		$clock = FrozenClock::at( '2026-07-28' );

		return new OrphanDetector( new StatusResolver( $clock ), $clock, $dormant_after_days );
	}

	/**
	 * A coupon that is live, recently used and has an expiry date is exactly
	 * what a healthy inventory looks like.
	 */
	public function test_a_healthy_coupon_is_not_an_orphan(): void {
		$coupon = CouponSnapshotBuilder::make()
			->expiring( '2026-12-01' )
			->created( '2026-07-01' )
			->last_used( '2026-07-20' )
			->build();

		$this->assertSame( array(), $this->detector()->reasons( $coupon ) );
		$this->assertFalse( $this->detector()->is_orphan( $coupon ) );
	}

	/**
	 * An active coupon with no expiry date runs forever by accident.
	 */
	public function test_an_active_coupon_without_an_expiry_date_is_an_orphan(): void {
		$coupon = CouponSnapshotBuilder::make()
			->created( '2026-07-01' )
			->last_used( '2026-07-20' )
			->build();

		$this->assertSame( array( OrphanReason::NO_EXPIRY_DATE ), $this->detector()->reasons( $coupon ) );
	}

	/**
	 * Only live coupons can be orphans. A coupon that is already off is not a
	 * relic to clean up, it is simply off — and reporting it would bury the
	 * findings that matter under ones that do not.
	 */
	public function test_a_coupon_that_is_not_active_is_never_an_orphan(): void {
		$unpublished = CouponSnapshotBuilder::make()->unpublished()->created( '2020-01-01' )->build();
		$exhausted   = CouponSnapshotBuilder::make()->created( '2020-01-01' )->limited_to( 1 )->used( 1 )->build();
		$expired     = CouponSnapshotBuilder::make()->created( '2020-01-01' )->expiring( '2021-01-01' )->build();
		$scheduled   = CouponSnapshotBuilder::make()->created( '2020-01-01' )->starting( '2027-01-01' )->build();

		foreach ( array( $unpublished, $exhausted, $expired, $scheduled ) as $coupon ) {
			$this->assertSame( array(), $this->detector()->reasons( $coupon ) );
		}
	}

	/**
	 * A coupon nobody has redeemed since well before the threshold is dormant.
	 */
	public function test_a_coupon_unused_beyond_the_threshold_is_dormant(): void {
		$coupon = CouponSnapshotBuilder::make()
			->expiring( '2026-12-01' )
			->created( '2026-01-01' )
			->last_used( '2026-02-01' )
			->build();

		$this->assertSame( array( OrphanReason::DORMANT ), $this->detector()->reasons( $coupon ) );
	}

	/**
	 * Recent use clears the dormancy flag.
	 */
	public function test_a_recently_used_coupon_is_not_dormant(): void {
		$coupon = CouponSnapshotBuilder::make()
			->expiring( '2026-12-01' )
			->created( '2020-01-01' )
			->last_used( '2026-07-20' )
			->build();

		$this->assertSame( array(), $this->detector()->reasons( $coupon ) );
	}

	/**
	 * A coupon that has never been used falls back to its creation date. A
	 * brand-new coupon has simply not had its chance yet, and flagging it would
	 * make the report useless on the day it is most likely to be read.
	 */
	public function test_a_never_used_but_recently_created_coupon_is_not_dormant(): void {
		$coupon = CouponSnapshotBuilder::make()
			->expiring( '2026-12-01' )
			->created( '2026-07-01' )
			->build();

		$this->assertSame( array(), $this->detector()->reasons( $coupon ) );
	}

	/**
	 * A coupon created long ago and never used once is dormant.
	 */
	public function test_a_never_used_and_long_standing_coupon_is_dormant(): void {
		$coupon = CouponSnapshotBuilder::make()
			->expiring( '2026-12-01' )
			->created( '2026-01-01' )
			->build();

		$this->assertSame( array( OrphanReason::DORMANT ), $this->detector()->reasons( $coupon ) );
	}

	/**
	 * The threshold is configurable, as §8.4 requires.
	 */
	public function test_the_dormancy_threshold_is_configurable(): void {
		$coupon = CouponSnapshotBuilder::make()
			->expiring( '2026-12-01' )
			->created( '2026-01-01' )
			->last_used( '2026-06-01' )
			->build();

		$this->assertSame( array(), $this->detector( 90 )->reasons( $coupon ) );
		$this->assertSame( array( OrphanReason::DORMANT ), $this->detector( 30 )->reasons( $coupon ) );
	}

	/**
	 * A threshold of zero days would make every coupon dormant the instant it
	 * was used, which is never what anyone meant to configure.
	 */
	public function test_it_rejects_a_non_positive_threshold(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->detector( 0 );
	}

	/**
	 * A live code left behind by a campaign whose other codes have all expired.
	 */
	public function test_a_live_code_from_a_dead_campaign_is_an_orphan(): void {
		$survivor = $this->campaign_code( 'SUMMER24-A', 1, '2026-12-01' );
		$dead     = $this->campaign_code( 'SUMMER24-B', 2, '2026-06-01' );

		$this->assertSame(
			array( OrphanReason::DEAD_CAMPAIGN ),
			$this->detector()->reasons( $survivor, array( $survivor, $dead ) )
		);
	}

	/**
	 * A campaign with another live code is still running.
	 */
	public function test_a_campaign_with_another_live_code_is_not_dead(): void {
		$one   = $this->campaign_code( 'SUMMER24-A', 1, '2026-12-01' );
		$two   = $this->campaign_code( 'SUMMER24-B', 2, '2026-12-01' );
		$stale = $this->campaign_code( 'SUMMER24-C', 3, '2026-06-01' );

		$this->assertSame( array(), $this->detector()->reasons( $one, array( $one, $two, $stale ) ) );
	}

	/**
	 * A coupon is not its own dead campaign, however lonely it is.
	 */
	public function test_a_coupon_alone_in_its_campaign_is_not_a_dead_campaign(): void {
		$only = $this->campaign_code( 'SUMMER24-A', 1, '2026-12-01' );

		$this->assertSame( array(), $this->detector()->reasons( $only, array( $only ) ) );
	}

	/**
	 * A campaign is inferred from a separator, not from a shared spelling.
	 * Grouping WELCOME with WELCOME2 on the strength of a common substring
	 * would invent campaigns nobody ran.
	 */
	public function test_codes_without_a_separator_are_not_a_campaign(): void {
		$one = $this->campaign_code( 'WELCOME', 1, '2026-12-01' );
		$two = $this->campaign_code( 'WELCOME2', 2, '2026-06-01' );

		$this->assertSame( array(), $this->detector()->reasons( $one, array( $one, $two ) ) );
	}

	/**
	 * Underscores separate campaigns from codes just as hyphens do.
	 */
	public function test_an_underscore_also_separates_a_campaign_from_its_code(): void {
		$survivor = $this->campaign_code( 'BLACKFRIDAY_01', 1, '2026-12-01' );
		$dead     = $this->campaign_code( 'BLACKFRIDAY_02', 2, '2026-06-01' );

		$this->assertSame(
			array( OrphanReason::DEAD_CAMPAIGN ),
			$this->detector()->reasons( $survivor, array( $survivor, $dead ) )
		);
	}

	/**
	 * Without an inventory to compare against, campaign death is simply not
	 * asked about — the other rules still apply.
	 */
	public function test_campaign_death_is_not_reported_without_an_inventory(): void {
		$survivor = $this->campaign_code( 'SUMMER24-A', 1, '2026-12-01' );

		$this->assertSame( array(), $this->detector()->reasons( $survivor ) );
	}

	/**
	 * Every applicable reason is reported, in a stable order, so a coupon that
	 * is wrong in three ways says so three times.
	 */
	public function test_all_applicable_reasons_are_reported_together(): void {
		$survivor = CouponSnapshotBuilder::make()
			->with_id( 1 )
			->with_code( 'SUMMER24-A' )
			->created( '2026-01-01' )
			->build();

		$dead = $this->campaign_code( 'SUMMER24-B', 2, '2026-06-01' );

		$this->assertSame(
			array( OrphanReason::NO_EXPIRY_DATE, OrphanReason::DORMANT, OrphanReason::DEAD_CAMPAIGN ),
			$this->detector()->reasons( $survivor, array( $survivor, $dead ) )
		);
		$this->assertTrue( $this->detector()->is_orphan( $survivor, array( $survivor, $dead ) ) );
	}

	/**
	 * The batch pass and the single-coupon pass must agree.
	 *
	 * They are two routes to the same rule, and the batch one exists only because
	 * the other is too slow to call in a loop. If they ever disagree, the screen
	 * shows something no test of the single-coupon rule would catch.
	 */
	public function test_judging_a_whole_inventory_agrees_with_judging_one_at_a_time(): void {
		$inventory = array(
			$this->campaign_code( 'summer-a', 1, '2026-01-01' ),
			$this->campaign_code( 'summer-b', 2, '2026-01-02' ),
			$this->campaign_code( 'summer-c', 3, '2027-01-01' ),
			$this->campaign_code( 'winter-a', 4, '2027-01-01' ),
			CouponSnapshotBuilder::make()->with_id( 5 )->with_code( 'standalone' )->build(),
		);

		$detector = $this->detector();
		$batch    = $detector->reasons_for_all( $inventory );

		foreach ( $inventory as $coupon ) {
			$this->assertSame(
				$detector->reasons( $coupon, $inventory ),
				$batch[ $coupon->id->value ],
				sprintf( 'The two passes disagree about %s.', $coupon->code )
			);
		}
	}

	/**
	 * A campaign whose every other code has expired is reported once the tally is
	 * counted rather than searched, which is the behaviour the batch pass changed.
	 */
	public function test_the_batch_pass_still_finds_a_dead_campaign(): void {
		$survivor = $this->campaign_code( 'summer-c', 3, '2027-01-01' );

		$reasons = $this->detector()->reasons_for_all(
			array(
				$this->campaign_code( 'summer-a', 1, '2026-01-01' ),
				$this->campaign_code( 'summer-b', 2, '2026-01-02' ),
				$survivor,
			)
		);

		$this->assertContains( OrphanReason::DEAD_CAMPAIGN, $reasons[ $survivor->id->value ] );
	}

	/**
	 * A coupon whose siblings are still alive is not the remnant of anything.
	 */
	public function test_the_batch_pass_spares_a_live_campaign(): void {
		$coupon = $this->campaign_code( 'summer-c', 3, '2027-01-01' );

		$reasons = $this->detector()->reasons_for_all(
			array(
				$this->campaign_code( 'summer-a', 1, '2027-01-01' ),
				$coupon,
			)
		);

		$this->assertNotContains( OrphanReason::DEAD_CAMPAIGN, $reasons[ $coupon->id->value ] );
	}

	/**
	 * Build a campaign code that is recent enough never to be dormant.
	 *
	 * @param string $code    The coupon code.
	 * @param int    $id      Post ID, so siblings can be told apart.
	 * @param string $expires Expiry date.
	 */
	private function campaign_code( string $code, int $id, string $expires ): CouponSnapshot {
		return CouponSnapshotBuilder::make()
			->with_id( $id )
			->with_code( $code )
			->created( '2026-07-01' )
			->last_used( '2026-07-20' )
			->expiring( $expires )
			->build();
	}
}
