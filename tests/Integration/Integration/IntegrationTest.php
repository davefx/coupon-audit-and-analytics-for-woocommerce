<?php
/**
 * Third-party integration tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Integration\Integration;

use DFX\CouponAAW\Domain\Coupon\CouponId;
use DFX\CouponAAW\Integration\IntegrationRegistry;
use DFX\CouponAAW\Integration\WjecfIntegration;
use DFX\CouponAAW\Integration\YithPointsIntegration;
use DFX\CouponAAW\Plugin;
use DFX\CouponAAW\Repository\CouponRepositoryInterface;
use WC_Coupon;
use WP_UnitTestCase;

/**
 * The integrations read other plugins' storage, so what they do can only be
 * checked against data in that shape.
 *
 * Neither plugin is installed here, so `is_active()` is false and the hooks are
 * exercised directly. That is the honest limit of what can be tested without
 * owning a commercial plugin — and it is why the keys were read out of those
 * plugins' source rather than trusted from documentation.
 */
final class IntegrationTest extends WP_UnitTestCase {

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		remove_all_filters( 'dfxcaaw_coupon_query_args' );
		remove_all_filters( 'dfxcaaw_coupon_is_auto_applied' );

		parent::tear_down();
	}

	/**
	 * Create a coupon, optionally with extra meta.
	 *
	 * @param string               $code The coupon code.
	 * @param array<string, mixed> $meta Meta to stamp on it.
	 */
	private function create_coupon( string $code, array $meta = array() ): int {
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( '10' );
		$id = $coupon->save();

		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		return $id;
	}

	/**
	 * Neither plugin is present in the test shop, so neither integration
	 * volunteers itself.
	 */
	public function test_integrations_stay_inert_when_their_plugin_is_absent(): void {
		$registry = new IntegrationRegistry( array( new WjecfIntegration(), new YithPointsIntegration() ) );

		$this->assertSame( array(), $registry->active() );
	}

	/**
	 * The plugin registers both integrations, whether or not they apply.
	 */
	public function test_both_integrations_are_registered(): void {
		$registry = Plugin::get_instance()->container()->get( IntegrationRegistry::class );

		$this->assertInstanceOf( IntegrationRegistry::class, $registry );
	}

	/**
	 * A coupon marked as auto-applied by the extended-features plugin is
	 * reported as such. This is the only route to §8.3's most serious overlap
	 * grade, which WooCommerce alone can never reach.
	 */
	public function test_the_extended_features_plugin_can_mark_a_coupon_auto_applied(): void {
		$id = $this->create_coupon( 'autoapplied', array( '_wjecf_is_auto_coupon' => 'yes' ) );

		( new WjecfIntegration() )->register();

		$coupon = Plugin::get_instance()
			->container()
			->get( CouponRepositoryInterface::class )
			->find( new CouponId( $id ) );

		$this->assertNotNull( $coupon );
		$this->assertTrue( $coupon->is_auto_applied );
	}

	/**
	 * A coupon without that mark is not.
	 */
	public function test_an_ordinary_coupon_is_not_auto_applied(): void {
		$id = $this->create_coupon( 'ordinary' );

		( new WjecfIntegration() )->register();

		$coupon = Plugin::get_instance()
			->container()
			->get( CouponRepositoryInterface::class )
			->find( new CouponId( $id ) );

		$this->assertNotNull( $coupon );
		$this->assertFalse( $coupon->is_auto_applied );
	}

	/**
	 * Reward coupons are kept out of the inventory entirely.
	 *
	 * A points shop mints one per redemption, and tens of thousands of them
	 * would bury the coupons somebody actually chose — and, past a few hundred,
	 * would switch overlap detection off for all of them.
	 */
	public function test_generated_reward_coupons_are_excluded(): void {
		$this->create_coupon( 'realcoupon' );
		$this->create_coupon( 'ywpar_discount_12345', array( 'ywpar_coupon' => 1 ) );

		$repository = Plugin::get_instance()->container()->get( CouponRepositoryInterface::class );

		$this->assertCount( 2, $repository->all(), 'Both exist before the integration is attached.' );

		( new YithPointsIntegration() )->register();

		$codes = array_map( static fn ( $coupon ): string => $coupon->code, $repository->all() );

		$this->assertSame( array( 'realcoupon' ), $codes );
	}

	/**
	 * The exclusion is by the meta the plugin stamps, not by the code prefix.
	 * That prefix is filterable upstream, so a shop that renamed it would defeat
	 * a prefix match while the meta stays true.
	 */
	public function test_exclusion_follows_the_meta_rather_than_the_code(): void {
		$this->create_coupon( 'ywpar_discount_lookalike' );
		$this->create_coupon( 'renamed-reward', array( 'ywpar_coupon' => 1 ) );

		( new YithPointsIntegration() )->register();

		$codes = array_map(
			static fn ( $coupon ): string => $coupon->code,
			Plugin::get_instance()->container()->get( CouponRepositoryInterface::class )->all()
		);

		$this->assertSame(
			array( 'ywpar_discount_lookalike' ),
			$codes,
			'A coupon merely named like a reward is a real coupon; a renamed one is not.'
		);
	}

	/**
	 * Counting agrees with listing once coupons are excluded, or a screen
	 * promises more rows than it shows.
	 */
	public function test_counting_agrees_with_listing_after_exclusion(): void {
		$this->create_coupon( 'realcoupon' );
		$this->create_coupon( 'reward', array( 'ywpar_coupon' => 1 ) );

		( new YithPointsIntegration() )->register();

		$repository = Plugin::get_instance()->container()->get( CouponRepositoryInterface::class );

		$this->assertCount( $repository->count(), $repository->all() );
	}
}
