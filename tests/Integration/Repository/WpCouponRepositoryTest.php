<?php
/**
 * Coupon repository integration tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Integration\Repository;

use DateTimeZone;
use DFX\CouponAAW\Domain\Coupon\CouponId;
use DFX\CouponAAW\Domain\Coupon\CouponStatus;
use DFX\CouponAAW\Domain\Coupon\StatusResolver;
use DFX\CouponAAW\Repository\WpCouponRepository;
use DFX\CouponAAW\Tests\Fixtures\FrozenClock;
use WC_Coupon;
use WP_UnitTestCase;

/**
 * The first code in the project that touches the database.
 *
 * These tests exist because the mapping is where the plugin meets a decade of
 * WooCommerce storage decisions — a usage limit of zero meaning "unlimited", a
 * scheduled post standing in for a start date WooCommerce never had — and none
 * of that can be verified against a mock.
 */
final class WpCouponRepositoryTest extends WP_UnitTestCase {

	/**
	 * The subject under test.
	 *
	 * @var WpCouponRepository
	 */
	private WpCouponRepository $repository;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$this->repository = new WpCouponRepository( $wpdb, wp_timezone() );
	}

	/**
	 * An ID nobody issued yields nothing rather than an empty coupon.
	 */
	public function test_it_returns_null_for_an_unknown_id(): void {
		$this->assertNull( $this->repository->find( new CouponId( 999999 ) ) );
	}

	/**
	 * A post that is not a coupon is not a coupon, however valid its ID.
	 */
	public function test_it_returns_null_for_a_post_that_is_not_a_coupon(): void {
		$post_id = self::factory()->post->create();
		$this->assertIsInt( $post_id );

		$this->assertNull( $this->repository->find( new CouponId( $post_id ) ) );
	}

	/**
	 * The basics survive the round trip.
	 */
	public function test_it_maps_the_core_fields(): void {
		$id = $this->create_coupon(
			array(
				'code'        => 'summer24',
				'usage_limit' => 5,
				'usage_count' => 2,
			)
		);

		$coupon = $this->repository->find( new CouponId( $id ) );

		$this->assertNotNull( $coupon );
		$this->assertSame( $id, $coupon->id->value );
		$this->assertSame( 'summer24', $coupon->code );
		$this->assertTrue( $coupon->is_published );
		$this->assertSame( 5, $coupon->usage_limit );
		$this->assertSame( 2, $coupon->usage_count );
		$this->assertSame( gmdate( 'Y' ), $coupon->created_at->format( 'Y' ) );
	}

	/**
	 * WooCommerce stores "no limit" as zero. The domain says null, because a
	 * limit of zero would otherwise read as exhausted from birth — which is
	 * exactly the wrong answer for the most common coupon in any store.
	 */
	public function test_a_usage_limit_of_zero_becomes_unlimited(): void {
		$id = $this->create_coupon( array( 'usage_limit' => 0 ) );

		$coupon = $this->repository->find( new CouponId( $id ) );

		$this->assertNotNull( $coupon );
		$this->assertNull( $coupon->usage_limit );
	}

	/**
	 * An expiry date is read back as an instant.
	 */
	public function test_it_maps_the_expiry_date(): void {
		$id = $this->create_coupon( array( 'date_expires' => '2026-12-01' ) );

		$coupon = $this->repository->find( new CouponId( $id ) );

		$this->assertNotNull( $coupon );
		$this->assertNotNull( $coupon->expires_at );
		$this->assertSame( '2026-12-01', $coupon->expires_at->format( 'Y-m-d' ) );
	}

	/**
	 * Most coupons have no expiry at all, which is the finding the audit half
	 * of this plugin exists to surface.
	 */
	public function test_a_coupon_without_an_expiry_reports_none(): void {
		$coupon = $this->repository->find( new CouponId( $this->create_coupon() ) );

		$this->assertNotNull( $coupon );
		$this->assertNull( $coupon->expires_at );
	}

	/**
	 * A draft coupon is not live.
	 */
	public function test_a_draft_coupon_is_not_published(): void {
		$id = $this->create_coupon();
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);

		$coupon = $this->repository->find( new CouponId( $id ) );

		$this->assertNotNull( $coupon );
		$this->assertFalse( $coupon->is_published );
	}

	/**
	 * WooCommerce has no start date of its own. A coupon scheduled to publish
	 * later is the only thing core offers, so that post date is what the domain
	 * treats as the start of the window — and the coupon still counts as
	 * intended-to-be-live, or it would resolve to inactive and never scheduled.
	 */
	public function test_a_scheduled_coupon_carries_a_start_date(): void {
		$id     = $this->create_coupon();
		$future = gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) );

		wp_update_post(
			array(
				'ID'            => $id,
				'post_status'   => 'future',
				'post_date'     => get_date_from_gmt( $future ),
				'post_date_gmt' => $future,
			)
		);

		$coupon = $this->repository->find( new CouponId( $id ) );

		$this->assertNotNull( $coupon );
		$this->assertTrue( $coupon->is_published );
		$this->assertNotNull( $coupon->starts_at );
		$this->assertSame(
			substr( get_date_from_gmt( $future ), 0, 10 ),
			$coupon->starts_at->format( 'Y-m-d' )
		);
	}

	/**
	 * A published coupon has no start date to speak of.
	 */
	public function test_a_published_coupon_has_no_start_date(): void {
		$coupon = $this->repository->find( new CouponId( $this->create_coupon() ) );

		$this->assertNotNull( $coupon );
		$this->assertNull( $coupon->starts_at );
	}

	/**
	 * Every restriction WooCommerce offers ends up in the scope.
	 */
	public function test_it_maps_the_whole_scope(): void {
		$id = $this->create_coupon(
			array(
				'product_ids'                 => array( 11, 12 ),
				'excluded_product_ids'        => array( 13 ),
				'product_categories'          => array( 21 ),
				'excluded_product_categories' => array( 22 ),
				'exclude_sale_items'          => true,
			)
		);

		$coupon = $this->repository->find( new CouponId( $id ) );

		$this->assertNotNull( $coupon );
		$this->assertSame( array( 11, 12 ), $coupon->scope->included_products );
		$this->assertSame( array( 13 ), $coupon->scope->excluded_products );
		$this->assertSame( array( 21 ), $coupon->scope->included_categories );
		$this->assertSame( array( 22 ), $coupon->scope->excluded_categories );
		$this->assertTrue( $coupon->scope->excludes_sale_items );
	}

	/**
	 * An unrestricted coupon comes back universal, which is what makes it
	 * overlap with everything.
	 */
	public function test_an_unrestricted_coupon_has_universal_scope(): void {
		$coupon = $this->repository->find( new CouponId( $this->create_coupon() ) );

		$this->assertNotNull( $coupon );
		$this->assertTrue( $coupon->scope->is_universal() );
	}

	/**
	 * Last use comes from the analytics lookup table, and the most recent
	 * redemption is the one that counts.
	 */
	public function test_it_reads_the_last_use_from_the_lookup_table(): void {
		$id = $this->create_coupon();

		$this->record_usage( $id, '2026-03-01 10:00:00' );
		$this->record_usage( $id, '2026-06-15 09:30:00' );

		$coupon = $this->repository->find( new CouponId( $id ) );

		$this->assertNotNull( $coupon );
		$this->assertNotNull( $coupon->last_used_at );
		$this->assertSame( '2026-06-15', $coupon->last_used_at->format( 'Y-m-d' ) );
	}

	/**
	 * A coupon nobody has redeemed reports no last use, which sends the orphan
	 * detector to the creation date instead.
	 */
	public function test_an_unused_coupon_reports_no_last_use(): void {
		$coupon = $this->repository->find( new CouponId( $this->create_coupon() ) );

		$this->assertNotNull( $coupon );
		$this->assertNull( $coupon->last_used_at );
	}

	/**
	 * Listing returns coupons and nothing else.
	 */
	public function test_it_lists_every_coupon_and_only_coupons(): void {
		$this->create_coupon( array( 'code' => 'alpha' ) );
		$this->create_coupon( array( 'code' => 'beta' ) );
		self::factory()->post->create();

		$codes = array_map(
			static fn ( $coupon ): string => $coupon->code,
			$this->repository->all()
		);
		sort( $codes );

		$this->assertSame( array( 'alpha', 'beta' ), $codes );
	}

	/**
	 * Listing includes coupons that are not published, since an unpublished
	 * coupon is still part of the inventory being audited.
	 */
	public function test_listing_includes_unpublished_coupons(): void {
		$id = $this->create_coupon( array( 'code' => 'hidden' ) );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);

		$this->assertCount( 1, $this->repository->all() );
	}

	/**
	 * Counting does not require loading.
	 */
	public function test_it_counts_coupons(): void {
		$this->assertSame( 0, $this->repository->count() );

		$this->create_coupon( array( 'code' => 'alpha' ) );
		$this->create_coupon( array( 'code' => 'beta' ) );

		$this->assertSame( 2, $this->repository->count() );
	}

	/**
	 * Counting and listing must agree. They are computed different ways —
	 * one from WordPress's per-status tallies, the other from a query — and a
	 * trashed coupon is exactly the kind of thing that lands in one but not the
	 * other, leaving a screen that promises twelve coupons and shows eleven.
	 */
	public function test_counting_and_listing_agree_about_trashed_coupons(): void {
		$this->create_coupon( array( 'code' => 'alpha' ) );
		wp_trash_post( $this->create_coupon( array( 'code' => 'binned' ) ) );

		$this->assertCount( $this->repository->count(), $this->repository->all() );
	}

	/**
	 * The point of all of it: real stored data, run through the domain, gives
	 * the right answer. This is the only test in the project that exercises the
	 * repository and the status rules together.
	 */
	public function test_a_stored_coupon_resolves_its_status_through_the_domain(): void {
		$expired = $this->repository->find(
			new CouponId( $this->create_coupon( array( 'date_expires' => '2026-01-01' ) ) )
		);
		$live    = $this->repository->find(
			new CouponId( $this->create_coupon( array( 'code' => 'live' ) ) )
		);

		$resolver = new StatusResolver( FrozenClock::at( '2026-07-28' ) );

		$this->assertNotNull( $expired );
		$this->assertNotNull( $live );
		$this->assertSame( CouponStatus::EXPIRED, $resolver->resolve( $expired ) );
		$this->assertSame( CouponStatus::ACTIVE, $resolver->resolve( $live ) );
	}

	/**
	 * Listing coupons costs a fixed number of queries however many there are.
	 *
	 * `WC_Coupon` reads its meta one coupon at a time, so an inventory of five
	 * hundred once meant five hundred queries and several seconds. The repository
	 * fills WooCommerce's own meta cache in one query instead. This test is the
	 * guard on that: it compares the cost of listing three coupons with the cost
	 * of listing nine, and the difference has to be nothing.
	 */
	public function test_listing_costs_the_same_however_many_coupons_there_are(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->create_coupon( array( 'code' => 'few' . $i ) );
		}

		// Discarded. The first listing of a shop that has any coupons also asks
		// once whether WooCommerce's lookup table exists, and that answer is kept
		// for the rest of the request. Counting it here would read as growth.
		$this->repository->all();

		wp_cache_flush();
		$before = get_num_queries();
		$this->repository->all();
		$for_three = get_num_queries() - $before;

		for ( $i = 0; $i < 6; $i++ ) {
			$this->create_coupon( array( 'code' => 'many' . $i ) );
		}

		wp_cache_flush();
		$before   = get_num_queries();
		$coupons  = $this->repository->all();
		$for_nine = get_num_queries() - $before;

		$this->assertCount( 9, $coupons );
		$this->assertSame(
			$for_three,
			$for_nine,
			'Three times the coupons cost extra queries, so coupon meta is being read one coupon at a time again.'
		);
	}

	/**
	 * The cached meta carries the real `meta_id`s.
	 *
	 * Not cosmetic: WooCommerce tells existing meta from new by that ID. Cached
	 * without them, every save would re-insert the lot — and with a persistent
	 * object cache, long after the request that seeded it.
	 */
	public function test_saving_a_coupon_after_listing_does_not_duplicate_its_meta(): void {
		$id = $this->create_coupon( array( 'code' => 'roundtrip' ) );

		update_post_meta( $id, 'custom_note', 'kept' );

		wp_cache_flush();
		$this->repository->all();

		$coupon = new WC_Coupon( $id );
		$coupon->set_amount( 25 );
		$coupon->save();

		$this->assertSame(
			array( 'kept' ),
			get_post_meta( $id, 'custom_note', false ),
			'The meta was re-inserted rather than updated, so the cached rows had no meta_id.'
		);
	}

	/**
	 * Create a coupon through WooCommerce's own API, so the stored shape is
	 * whatever WooCommerce actually writes rather than what we assume.
	 *
	 * @param array<string, mixed> $args Coupon properties to set.
	 */
	private function create_coupon( array $args = array() ): int {
		$coupon = new WC_Coupon();
		$coupon->set_code( $args['code'] ?? 'test' );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 10 );

		foreach ( array( 'usage_limit', 'usage_count', 'date_expires', 'product_ids', 'excluded_product_ids', 'product_categories', 'excluded_product_categories', 'exclude_sale_items' ) as $property ) {
			if ( array_key_exists( $property, $args ) ) {
				$coupon->{'set_' . $property}( $args[ $property ] );
			}
		}

		return $coupon->save();
	}

	/**
	 * Record a redemption in the analytics lookup table.
	 *
	 * @param int    $coupon_id The coupon redeemed.
	 * @param string $when      Site-local datetime, which is what WooCommerce writes here.
	 */
	private function record_usage( int $coupon_id, string $when ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'wc_order_coupon_lookup',
			array(
				'order_id'        => wp_rand( 1000, 99999 ),
				'coupon_id'       => $coupon_id,
				'date_created'    => $when,
				'discount_amount' => 5.0,
			)
		);
	}
}
