<?php
/**
 * Overlap detection against real stored coupons.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Integration\Overlap;

use DFX\CouponAAW\Domain\Coupon\CouponId;
use DFX\CouponAAW\Domain\Overlap\OverlapSeverity;
use DFX\CouponAAW\Plugin;
use DFX\CouponAAW\Repository\CouponRepositoryInterface;
use DFX\CouponAAW\Service\InventoryService;
use WC_Coupon;
use WP_UnitTestCase;

/**
 * The auto-apply seam is the one part of overlap detection that cannot be
 * exercised without WordPress: it exists precisely because WooCommerce has no
 * auto-apply of its own, so only a real filter can prove it works.
 */
final class OverlapIntegrationTest extends WP_UnitTestCase {

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		remove_all_filters( 'dfxcaaw_coupon_is_auto_applied' );

		parent::tear_down();
	}

	/**
	 * Create a live, unrestricted coupon.
	 *
	 * @param string $code The coupon code.
	 */
	private function create_coupon( string $code ): int {
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 10 );
		$coupon->set_date_expires( '2030-12-01' );

		return $coupon->save();
	}

	/**
	 * The inventory service, built fresh from the container.
	 */
	private function service(): InventoryService {
		return Plugin::get_instance()->container()->get( InventoryService::class );
	}

	/**
	 * Two stored coupons that both apply to everything collide, and the
	 * collision reaches the screen's data through the real repository.
	 */
	public function test_two_stored_unrestricted_coupons_collide(): void {
		$this->create_coupon( 'alpha' );
		$this->create_coupon( 'beta' );

		$inventory = $this->service()->build();

		$this->assertTrue( $inventory->overlaps_were_checked() );
		$this->assertCount( 1, $inventory->overlaps ?? array() );
		$this->assertSame( OverlapSeverity::MEDIUM, ( $inventory->overlaps ?? array() )[0]->severity );
	}

	/**
	 * By default nothing is auto-applied, because WooCommerce has no such
	 * concept. The most serious grade is therefore unreachable out of the box,
	 * and saying so is more honest than pretending otherwise.
	 */
	public function test_nothing_is_auto_applied_by_default(): void {
		$id = $this->create_coupon( 'alpha' );

		$coupon = Plugin::get_instance()
			->container()
			->get( CouponRepositoryInterface::class )
			->find( new CouponId( $id ) );

		$this->assertNotNull( $coupon );
		$this->assertFalse( $coupon->is_auto_applied );
	}

	/**
	 * A plugin that adds auto-apply can say so, and the collision is graded up
	 * accordingly. This is the only route to a high-severity finding.
	 */
	public function test_a_filter_can_declare_a_coupon_auto_applied(): void {
		$this->create_coupon( 'alpha' );
		$this->create_coupon( 'beta' );

		add_filter( 'dfxcaaw_coupon_is_auto_applied', '__return_true' );

		$overlaps = $this->service()->build()->overlaps ?? array();

		$this->assertCount( 1, $overlaps );
		$this->assertSame( OverlapSeverity::HIGH, $overlaps[0]->severity );
	}

	/**
	 * The filter receives the coupon, so a plugin can answer per coupon rather
	 * than for all of them at once.
	 */
	public function test_the_filter_can_answer_for_one_coupon_only(): void {
		$auto = $this->create_coupon( 'auto' );
		$this->create_coupon( 'manual' );

		add_filter(
			'dfxcaaw_coupon_is_auto_applied',
			static fn ( bool $value, int $coupon_id ): bool => $coupon_id === $auto,
			10,
			2
		);

		$overlaps = $this->service()->build()->overlaps ?? array();

		$this->assertCount( 1, $overlaps );
		$this->assertSame(
			OverlapSeverity::MEDIUM,
			$overlaps[0]->severity,
			'One auto-applied coupon is not enough for the most serious grade.'
		);
	}

	/**
	 * Coupons restricted to different products are stored, read back and found
	 * not to collide — the index skipping them must not skip anything real.
	 */
	public function test_stored_coupons_on_different_products_do_not_collide(): void {
		$one = new WC_Coupon();
		$one->set_code( 'narrow-one' );
		$one->set_discount_type( 'percent' );
		$one->set_amount( 10 );
		$one->set_date_expires( '2030-12-01' );
		$one->set_product_ids( array( 111 ) );
		$one->save();

		$two = new WC_Coupon();
		$two->set_code( 'narrow-two' );
		$two->set_discount_type( 'percent' );
		$two->set_amount( 10 );
		$two->set_date_expires( '2030-12-01' );
		$two->set_product_ids( array( 222 ) );
		$two->save();

		$this->assertSame( array(), $this->service()->build()->overlaps ?? array() );
	}
}
