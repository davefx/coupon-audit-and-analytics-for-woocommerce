<?php
/**
 * Coupon editor notice integration tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Integration\Admin;

use DFX\CouponAAW\Admin\CouponEditorNotices;
use DFX\CouponAAW\Plugin;
use WC_Coupon;
use WP_UnitTestCase;

/**
 * §9 gives this feature one hard rule, and it is a product rule: warnings never
 * block a save. That is asserted here against a real save, because it is the
 * kind of promise that quietly breaks when someone later reaches for a
 * convenient validation hook.
 */
final class CouponEditorNoticesTest extends WP_UnitTestCase {

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		$GLOBALS['post'] = null;

		parent::tear_down();
	}

	/**
	 * Sign in as someone allowed to edit coupons.
	 */
	private function login(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertIsInt( $user_id );

		wp_set_current_user( $user_id );
	}

	/**
	 * Create a coupon.
	 *
	 * @param string      $code    The coupon code.
	 * @param string|null $expires Expiry date, or null for none.
	 * @param int         $limit   Usage limit, or 0 for unlimited.
	 */
	private function create_coupon( string $code, ?string $expires = null, int $limit = 0 ): int {
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 10 );
		$coupon->set_usage_limit( $limit );

		if ( null !== $expires ) {
			$coupon->set_date_expires( $expires );
		}

		return $coupon->save();
	}

	/**
	 * Put the admin on the edit screen for a given coupon and capture the
	 * notices it produces.
	 *
	 * @param int $coupon_id The coupon being edited.
	 */
	private function notices_for( int $coupon_id ): string {
		set_current_screen( 'shop_coupon' );
		$GLOBALS['post'] = get_post( $coupon_id );

		ob_start();
		Plugin::get_instance()->container()->get( CouponEditorNotices::class )->render();

		return (string) ob_get_clean();
	}

	/**
	 * A coupon with no expiry and no cap is warned about on both counts.
	 */
	public function test_it_warns_about_a_missing_expiry_and_usage_limit(): void {
		$this->login();
		$id = $this->create_coupon( 'careless' );

		$html = $this->notices_for( $id );

		$this->assertStringContainsString( 'no expiry date', $html );
		$this->assertStringContainsString( 'no usage limit', $html );
	}

	/**
	 * A coupon with both set is left alone. A warning that fires on every
	 * coupon teaches the user to ignore warnings.
	 */
	public function test_a_sound_coupon_produces_no_notices(): void {
		$this->login();
		$id = $this->create_coupon( 'careful', '2030-12-01', 100 );

		$this->assertSame( '', $this->notices_for( $id ) );
	}

	/**
	 * An overlap names the coupon it collides with, so there is something to
	 * act on.
	 */
	public function test_it_names_the_coupon_an_overlap_collides_with(): void {
		$this->login();
		$this->create_coupon( 'existing', '2030-12-01', 100 );
		$id = $this->create_coupon( 'candidate', '2030-12-01', 100 );

		$html = $this->notices_for( $id );

		$this->assertStringContainsString( 'same products', $html );
		$this->assertStringContainsString( 'existing', $html );
	}

	/**
	 * Warnings are advisory markup, never errors. The class WordPress renders
	 * is the difference between "look at this" and "you have done something
	 * wrong".
	 */
	public function test_notices_are_warnings_rather_than_errors(): void {
		$this->login();
		$id = $this->create_coupon( 'careless' );

		$html = $this->notices_for( $id );

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringNotContainsString( 'notice-error', $html );
	}

	/**
	 * The hard rule of §9: a coupon with every possible warning against it
	 * still saves, and still saves the values it was given.
	 */
	public function test_warnings_never_block_a_save(): void {
		$this->login();
		$this->create_coupon( 'existing', '2030-12-01', 100 );

		$id = $this->create_coupon( 'everything-wrong' );
		$this->assertNotEmpty( $this->notices_for( $id ), 'This coupon should attract warnings.' );

		$coupon = new WC_Coupon( $id );
		$coupon->set_amount( 25 );
		$saved = $coupon->save();

		$this->assertSame( $id, $saved );
		$this->assertSame( '25', ( new WC_Coupon( $id ) )->get_amount() );
	}

	/**
	 * Nothing is rendered on screens that are not a coupon being edited.
	 */
	public function test_it_renders_nothing_outside_the_coupon_editor(): void {
		$this->login();
		$id = $this->create_coupon( 'careless' );

		set_current_screen( 'edit-post' );
		$GLOBALS['post'] = get_post( $id );

		ob_start();
		Plugin::get_instance()->container()->get( CouponEditorNotices::class )->render();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * A user without the capability sees nothing, even on the right screen.
	 */
	public function test_it_renders_nothing_without_the_capability(): void {
		$id = $this->create_coupon( 'careless' );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertIsInt( $user_id );
		wp_set_current_user( $user_id );

		$this->assertSame( '', $this->notices_for( $id ) );
	}
}
