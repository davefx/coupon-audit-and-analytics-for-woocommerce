<?php
/**
 * Pre-publish validation unit tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Service;

use DFX\CouponAAW\Domain\Coupon\CouponScope;
use DFX\CouponAAW\Domain\Coupon\CouponSnapshot;
use DFX\CouponAAW\Domain\Coupon\StatusResolver;
use DFX\CouponAAW\Domain\Overlap\OverlapDetector;
use DFX\CouponAAW\Domain\Overlap\OverlapSeverity;
use DFX\CouponAAW\Domain\Overlap\ScopeIndex;
use DFX\CouponAAW\Service\PrePublishValidator;
use DFX\CouponAAW\Service\PrePublishWarning;
use DFX\CouponAAW\Service\PrePublishWarningType;
use DFX\CouponAAW\Tests\Fixtures\CouponSnapshotBuilder;
use DFX\CouponAAW\Tests\Fixtures\FrozenClock;
use DFX\CouponAAW\Tests\Fixtures\InMemoryCouponRepository;
use PHPUnit\Framework\TestCase;

/**
 * The check that runs while a coupon is in front of the person editing it (§9).
 *
 * §9 gives this feature one hard rule and it is a product rule, not a technical
 * one: warnings never block a save. An analytics plugin that prevents work gets
 * uninstalled the first Tuesday. Nothing here returns anything a caller could
 * mistake for a veto — the validator reports, and the caller informs.
 */
final class PrePublishValidatorTest extends TestCase {

	/**
	 * A validator over a fixed store, with the clock at 2026-07-28.
	 *
	 * @param list<CouponSnapshot> $stored Coupons already in the store.
	 */
	private function validator( array $stored = array() ): PrePublishValidator {
		$clock = FrozenClock::at( '2026-07-28' );

		return new PrePublishValidator(
			new InMemoryCouponRepository( $stored ),
			new OverlapDetector( new StatusResolver( $clock ), new ScopeIndex() )
		);
	}

	/**
	 * Reduce warnings to their types, for readable assertions.
	 *
	 * @param list<PrePublishWarning> $warnings The warnings produced.
	 *
	 * @return list<string>
	 */
	private function types( array $warnings ): array {
		return array_map(
			static fn ( PrePublishWarning $warning ): string => $warning->type->value,
			$warnings
		);
	}

	/**
	 * A well-formed coupon, so tests can vary one thing at a time.
	 *
	 * @param int    $id   Post ID.
	 * @param string $code Coupon code.
	 */
	private function sound( int $id, string $code ): CouponSnapshotBuilder {
		return CouponSnapshotBuilder::make()
			->with_id( $id )
			->with_code( $code )
			->created( '2026-07-01' )
			->last_used( '2026-07-20' )
			->expiring( '2026-12-01' )
			->limited_to( 100 )
			->with_scope( new CouponScope( included_products: array( 10 ) ) );
	}

	/**
	 * A coupon with an expiry, a usage cap, a narrow scope and no neighbours
	 * has nothing wrong with it.
	 */
	public function test_a_sound_coupon_produces_no_warnings(): void {
		$coupon = $this->sound( 1, 'good' )->build();

		$this->assertSame( array(), $this->validator( array( $coupon ) )->validate( $coupon ) );
	}

	/**
	 * A coupon with no expiry date is the single most common way a store ends
	 * up with a discount running for years.
	 */
	public function test_it_warns_about_a_missing_expiry_date(): void {
		$coupon = $this->sound( 1, 'forever' )->build();
		$coupon = CouponSnapshotBuilder::make()
			->with_id( 1 )->with_code( 'forever' )
			->created( '2026-07-01' )->last_used( '2026-07-20' )
			->limited_to( 100 )
			->with_scope( new CouponScope( included_products: array( 10 ) ) )
			->build();

		$this->assertSame(
			array( PrePublishWarningType::NO_EXPIRY_DATE->value ),
			$this->types( $this->validator( array( $coupon ) )->validate( $coupon ) )
		);
	}

	/**
	 * A coupon with no usage limit can be redeemed without bound.
	 */
	public function test_it_warns_about_a_missing_usage_limit(): void {
		$coupon = CouponSnapshotBuilder::make()
			->with_id( 1 )->with_code( 'unlimited' )
			->created( '2026-07-01' )->last_used( '2026-07-20' )
			->expiring( '2026-12-01' )
			->with_scope( new CouponScope( included_products: array( 10 ) ) )
			->build();

		$this->assertSame(
			array( PrePublishWarningType::NO_USAGE_LIMIT->value ),
			$this->types( $this->validator( array( $coupon ) )->validate( $coupon ) )
		);
	}

	/**
	 * A coupon that collides with one already in the store is reported, with
	 * the coupon it collides with named — a warning that says only "this
	 * overlaps something" gives the user nothing to act on.
	 */
	public function test_it_warns_about_an_overlap_and_names_the_other_coupon(): void {
		$existing  = $this->sound( 1, 'existing' )->build();
		$candidate = $this->sound( 2, 'candidate' )->build();

		$warnings = $this->validator( array( $existing, $candidate ) )->validate( $candidate );

		$this->assertSame( array( PrePublishWarningType::OVERLAPS_EXISTING->value ), $this->types( $warnings ) );
		$this->assertCount( 1, $warnings[0]->related );
		$this->assertSame( 'existing', $warnings[0]->related[0]->code );
		$this->assertSame( OverlapSeverity::MEDIUM, $warnings[0]->severity );
	}

	/**
	 * A coupon does not collide with itself, however many times it appears in
	 * the store it was read from.
	 */
	public function test_a_coupon_does_not_overlap_itself(): void {
		$coupon = $this->sound( 1, 'alone' )->build();

		$this->assertSame( array(), $this->types( $this->validator( array( $coupon ) )->validate( $coupon ) ) );
	}

	/**
	 * Coupons that cannot both apply to the same product are not reported.
	 */
	public function test_it_does_not_warn_about_a_coupon_it_cannot_collide_with(): void {
		$existing  = $this->sound( 1, 'elsewhere' )
			->with_scope( new CouponScope( included_products: array( 99 ) ) )
			->build();
		$candidate = $this->sound( 2, 'candidate' )->build();

		$this->assertSame(
			array(),
			$this->types( $this->validator( array( $existing, $candidate ) )->validate( $candidate ) )
		);
	}

	/**
	 * Every colliding coupon is named, and the worst grade is the one reported.
	 */
	public function test_it_reports_every_collision_and_the_worst_grade(): void {
		$sibling = $this->sound( 1, 'sibling' )->build();
		$later   = $this->sound( 3, 'much-later' )->starting( '2027-01-01' )->expiring( '2027-06-01' )->build();
		$subject = $this->sound( 2, 'subject' )->build();

		$warnings = $this->validator( array( $sibling, $later, $subject ) )->validate( $subject );

		$this->assertCount( 2, $warnings[0]->related );
		$this->assertSame( OverlapSeverity::MEDIUM, $warnings[0]->severity );
	}

	/**
	 * Every applicable warning is reported, in a stable order.
	 */
	public function test_all_warnings_are_reported_together(): void {
		$existing = $this->sound( 1, 'existing' )->build();
		$subject  = CouponSnapshotBuilder::make()
			->with_id( 2 )->with_code( 'careless' )
			->created( '2026-07-01' )->last_used( '2026-07-20' )
			->with_scope( new CouponScope( included_products: array( 10 ) ) )
			->build();

		$this->assertSame(
			array(
				PrePublishWarningType::NO_EXPIRY_DATE->value,
				PrePublishWarningType::NO_USAGE_LIMIT->value,
				PrePublishWarningType::OVERLAPS_EXISTING->value,
			),
			$this->types( $this->validator( array( $existing, $subject ) )->validate( $subject ) )
		);
	}

	/**
	 * A coupon that cannot be redeemed collides with nothing, so editing an
	 * expired coupon does not produce overlap noise about live ones.
	 */
	public function test_an_unpublished_coupon_is_not_reported_as_colliding(): void {
		$existing = $this->sound( 1, 'existing' )->build();
		$subject  = $this->sound( 2, 'draft' )->unpublished()->build();

		$this->assertSame(
			array(),
			$this->types( $this->validator( array( $existing, $subject ) )->validate( $subject ) )
		);
	}

	/**
	 * Past the point where comparing every coupon is affordable, the overlap
	 * check is skipped rather than run while the editor waits. The cheap checks
	 * still run: a large store is exactly where a forgotten expiry hides.
	 */
	public function test_the_overlap_check_is_skipped_on_a_large_store(): void {
		$stored = array();

		for ( $i = 1; $i <= OverlapDetector::SYNCHRONOUS_LIMIT + 1; $i++ ) {
			$stored[] = CouponSnapshotBuilder::make()
				->with_id( $i )->with_code( 'c' . $i )
				->created( '2026-07-01' )->last_used( '2026-07-20' )
				->build();
		}

		$warnings = $this->validator( $stored )->validate( $stored[0] );

		$this->assertSame(
			array(
				PrePublishWarningType::NO_EXPIRY_DATE->value,
				PrePublishWarningType::NO_USAGE_LIMIT->value,
			),
			$this->types( $warnings )
		);
	}

	/**
	 * A coupon the store has never seen — one being validated before its first
	 * save — is still checked against everything already there.
	 */
	public function test_a_coupon_not_yet_in_the_store_is_still_checked(): void {
		$existing  = $this->sound( 1, 'existing' )->build();
		$candidate = $this->sound( 99, 'brand-new' )->build();

		$warnings = $this->validator( array( $existing ) )->validate( $candidate );

		$this->assertSame( array( PrePublishWarningType::OVERLAPS_EXISTING->value ), $this->types( $warnings ) );
		$this->assertSame( 'existing', $warnings[0]->related[0]->code );
	}
}
