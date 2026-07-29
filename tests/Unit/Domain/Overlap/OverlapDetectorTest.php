<?php
/**
 * Overlap detection unit tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Domain\Overlap;

use DFX\CouponAAW\Domain\Coupon\CouponScope;
use DFX\CouponAAW\Domain\Coupon\CouponSnapshot;
use DFX\CouponAAW\Domain\Coupon\StatusResolver;
use DFX\CouponAAW\Domain\Overlap\Overlap;
use DFX\CouponAAW\Domain\Overlap\OverlapDetector;
use DFX\CouponAAW\Domain\Overlap\OverlapSeverity;
use DFX\CouponAAW\Domain\Overlap\ScopeIndex;
use DFX\CouponAAW\Tests\Fixtures\CouponSnapshotBuilder;
use DFX\CouponAAW\Tests\Fixtures\FrozenClock;
use PHPUnit\Framework\TestCase;

/**
 * Two coupons that can both apply to the same product at the same time (§8.3).
 *
 * This is the finding a store owner cannot reach by any other means: nothing in
 * WooCommerce compares one coupon against another.
 */
final class OverlapDetectorTest extends TestCase {

	/**
	 * A detector whose clock reads 2026-07-28.
	 */
	private function detector(): OverlapDetector {
		return new OverlapDetector(
			new StatusResolver( FrozenClock::at( '2026-07-28' ) ),
			new ScopeIndex()
		);
	}

	/**
	 * A live coupon with a wide window, so tests can vary one thing at a time.
	 *
	 * @param int              $id    Post ID.
	 * @param string           $code  Coupon code.
	 * @param CouponScope|null $scope Scope, universal when omitted.
	 */
	private function live( int $id, string $code, ?CouponScope $scope = null ): CouponSnapshotBuilder {
		$builder = CouponSnapshotBuilder::make()
			->with_id( $id )
			->with_code( $code )
			->created( '2026-07-01' )
			->last_used( '2026-07-20' )
			->expiring( '2026-12-01' );

		return null === $scope ? $builder : $builder->with_scope( $scope );
	}

	/**
	 * Two live coupons that both apply to everything, at the same time.
	 */
	public function test_two_live_unrestricted_coupons_overlap(): void {
		$overlaps = $this->detector()->detect(
			array(
				$this->live( 1, 'alpha' )->build(),
				$this->live( 2, 'beta' )->build(),
			)
		);

		$this->assertCount( 1, $overlaps );
		$this->assertSame( OverlapSeverity::MEDIUM, $overlaps[0]->severity );
	}

	/**
	 * Coupons restricted to different products cannot both apply to anything.
	 */
	public function test_coupons_on_different_products_do_not_overlap(): void {
		$overlaps = $this->detector()->detect(
			array(
				$this->live( 1, 'alpha', new CouponScope( included_products: array( 10 ) ) )->build(),
				$this->live( 2, 'beta', new CouponScope( included_products: array( 20 ) ) )->build(),
			)
		);

		$this->assertSame( array(), $overlaps );
	}

	/**
	 * Coupons sharing a product do overlap.
	 */
	public function test_coupons_sharing_a_product_overlap(): void {
		$overlaps = $this->detector()->detect(
			array(
				$this->live( 1, 'alpha', new CouponScope( included_products: array( 10, 11 ) ) )->build(),
				$this->live( 2, 'beta', new CouponScope( included_products: array( 11, 12 ) ) )->build(),
			)
		);

		$this->assertCount( 1, $overlaps );
	}

	/**
	 * Scope intersection with no shared time is the mildest finding: the
	 * coupons collide on paper but never in a customer's basket.
	 */
	public function test_scope_intersection_without_shared_time_is_low(): void {
		$overlaps = $this->detector()->detect(
			array(
				$this->live( 1, 'ending' )->expiring( '2026-08-01' )->build(),
				$this->live( 2, 'starting' )->starting( '2026-09-01' )->expiring( '2026-12-01' )->build(),
			)
		);

		$this->assertCount( 1, $overlaps );
		$this->assertSame( OverlapSeverity::LOW, $overlaps[0]->severity );
	}

	/**
	 * Both applying automatically to the same product is the worst case: the
	 * collision needs no customer to discover it.
	 */
	public function test_two_auto_applied_coupons_are_high_severity(): void {
		$overlaps = $this->detector()->detect(
			array(
				$this->live( 1, 'alpha' )->auto_applied()->build(),
				$this->live( 2, 'beta' )->auto_applied()->build(),
			)
		);

		$this->assertCount( 1, $overlaps );
		$this->assertSame( OverlapSeverity::HIGH, $overlaps[0]->severity );
	}

	/**
	 * One auto-applied coupon is not enough: a customer still has to find the
	 * other one.
	 */
	public function test_one_auto_applied_coupon_is_only_medium(): void {
		$overlaps = $this->detector()->detect(
			array(
				$this->live( 1, 'alpha' )->auto_applied()->build(),
				$this->live( 2, 'beta' )->build(),
			)
		);

		$this->assertSame( OverlapSeverity::MEDIUM, $overlaps[0]->severity );
	}

	/**
	 * Auto-applied coupons whose windows never meet cannot collide either, so
	 * they fall back to the mildest finding rather than the worst.
	 */
	public function test_auto_applied_coupons_without_shared_time_are_low(): void {
		$overlaps = $this->detector()->detect(
			array(
				$this->live( 1, 'ending' )->auto_applied()->expiring( '2026-08-01' )->build(),
				$this->live( 2, 'starting' )->auto_applied()->starting( '2026-09-01' )->expiring( '2026-12-01' )->build(),
			)
		);

		$this->assertSame( OverlapSeverity::LOW, $overlaps[0]->severity );
	}

	/**
	 * A coupon that can never be redeemed cannot collide with anything. Listing
	 * it would bury the findings that matter under ones that do not.
	 *
	 * @dataProvider provide_dead_coupons
	 *
	 * @param CouponSnapshot $dead A coupon in a non-live state.
	 */
	public function test_coupons_that_are_not_live_are_ignored( CouponSnapshot $dead ): void {
		$overlaps = $this->detector()->detect(
			array( $this->live( 1, 'alpha' )->build(), $dead )
		);

		$this->assertSame( array(), $overlaps );
	}

	/**
	 * Coupons in states that cannot be redeemed.
	 *
	 * @return array<string, array{CouponSnapshot}>
	 */
	public static function provide_dead_coupons(): array {
		$base = static fn (): CouponSnapshotBuilder => CouponSnapshotBuilder::make()
			->with_id( 2 )
			->with_code( 'dead' )
			->created( '2026-01-01' );

		return array(
			'expired'     => array( $base()->expiring( '2026-06-01' )->build() ),
			'exhausted'   => array( $base()->limited_to( 1 )->used( 1 )->build() ),
			'unpublished' => array( $base()->unpublished()->build() ),
		);
	}

	/**
	 * A coupon that has not opened yet still collides with one that is live —
	 * that is precisely the collision worth knowing about before it happens.
	 */
	public function test_a_scheduled_coupon_still_counts(): void {
		$overlaps = $this->detector()->detect(
			array(
				$this->live( 1, 'now' )->build(),
				$this->live( 2, 'soon' )->starting( '2026-09-01' )->build(),
			)
		);

		$this->assertCount( 1, $overlaps );
	}

	/**
	 * A coupon is never reported as overlapping itself.
	 */
	public function test_a_coupon_does_not_overlap_itself(): void {
		$this->assertSame( array(), $this->detector()->detect( array( $this->live( 1, 'alone' )->build() ) ) );
	}

	/**
	 * Each pair is reported once, not once from each side.
	 */
	public function test_each_pair_is_reported_once(): void {
		$overlaps = $this->detector()->detect(
			array(
				$this->live( 1, 'alpha' )->build(),
				$this->live( 2, 'beta' )->build(),
				$this->live( 3, 'gamma' )->build(),
			)
		);

		$this->assertCount( 3, $overlaps );
	}

	/**
	 * The pair is ordered by ID, so the same collision always reads the same
	 * way whatever order the repository returned.
	 */
	public function test_the_pair_is_ordered_by_id(): void {
		$overlaps = $this->detector()->detect(
			array(
				$this->live( 7, 'later' )->build(),
				$this->live( 3, 'earlier' )->build(),
			)
		);

		$this->assertSame( 3, $overlaps[0]->one->id->value );
		$this->assertSame( 7, $overlaps[0]->other->id->value );
	}

	/**
	 * An overlap knows which coupons it concerns, so a screen can show the
	 * findings against either row.
	 */
	public function test_an_overlap_knows_which_coupons_it_involves(): void {
		$overlap = $this->detector()->detect(
			array( $this->live( 1, 'alpha' )->build(), $this->live( 2, 'beta' )->build() )
		)[0];

		$this->assertTrue( $overlap->involves( $overlap->one->id ) );
		$this->assertTrue( $overlap->involves( $overlap->other->id ) );
		$this->assertFalse( $overlap->involves( ( $this->live( 9, 'other' )->build() )->id ) );
	}

	/**
	 * The indexed search must find exactly what comparing every pair would.
	 *
	 * §8.3 requires indexing before comparison on large inventories, and an
	 * index that quietly drops a pair produces a clean report that is wrong —
	 * the worst possible outcome for a tool whose value is telling you what you
	 * missed. This pins the optimisation to the naive answer over a deliberately
	 * awkward mixture of scopes.
	 */
	public function test_the_indexed_search_agrees_with_comparing_every_pair(): void {
		$coupons = array(
			$this->live( 1, 'universal' )->build(),
			$this->live( 2, 'product-10', new CouponScope( included_products: array( 10 ) ) )->build(),
			$this->live( 3, 'product-10-again', new CouponScope( included_products: array( 10 ) ) )->build(),
			$this->live( 4, 'product-20', new CouponScope( included_products: array( 20 ) ) )->build(),
			$this->live( 5, 'category-5', new CouponScope( included_categories: array( 5 ) ) )->build(),
			$this->live( 6, 'category-5-again', new CouponScope( included_categories: array( 5 ) ) )->build(),
			$this->live( 7, 'category-6', new CouponScope( included_categories: array( 6 ) ) )->build(),
			$this->live( 8, 'sale-excluded', new CouponScope( included_products: array( 20 ), excludes_sale_items: true ) )->build(),
		);

		$expected = array();

		foreach ( $coupons as $i => $one ) {
			foreach ( array_slice( $coupons, $i + 1 ) as $other ) {
				if ( $one->scope->may_intersect( $other->scope ) ) {
					$expected[] = $one->code . ' + ' . $other->code;
				}
			}
		}

		$found = array_map(
			static fn ( Overlap $overlap ): string => $overlap->one->code . ' + ' . $overlap->other->code,
			$this->detector()->detect( $coupons )
		);

		sort( $expected );
		sort( $found );

		$this->assertSame( $expected, $found );
	}
}
