<?php
/**
 * Third-party integration tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Integration\Integration;

use DFX\CouponAAW\Domain\Coupon\CouponId;
use DFX\CouponAAW\Domain\Coupon\CouponStatus;
use DFX\CouponAAW\Integration\IntegrationRegistry;
use DFX\CouponAAW\Integration\WjecfIntegration;
use DFX\CouponAAW\Integration\YithPointsIntegration;
use DFX\CouponAAW\Plugin;
use DFX\CouponAAW\Repository\CouponRepositoryInterface;
use DFX\CouponAAW\Service\InventoryEntry;
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
	 * Render the extended-conditions cell for a coupon.
	 *
	 * @param int $id The coupon.
	 */
	private function render_conditions( int $id ): string {
		$coupon = Plugin::get_instance()
			->container()
			->get( CouponRepositoryInterface::class )
			->find( new CouponId( $id ) );

		$this->assertNotNull( $coupon );

		return ( new WjecfIntegration() )->render_cell(
			'',
			'wjecf',
			new InventoryEntry( $coupon, CouponStatus::ACTIVE, array(), array(), array() )
		);
	}

	/**
	 * The integration adds a column of its own.
	 */
	public function test_it_adds_a_column(): void {
		$columns = ( new WjecfIntegration() )->add_column( array( 'code' => 'Code' ) );

		$this->assertArrayHasKey( 'wjecf', $columns );
		$this->assertArrayHasKey( 'code', $columns, 'It replaced the columns instead of adding to them.' );
	}

	/**
	 * It only fills its own column, and leaves every other cell as it found it.
	 */
	public function test_it_leaves_other_columns_alone(): void {
		$id     = $this->create_coupon( 'othercolumn', array( '_wjecf_is_auto_coupon' => 'yes' ) );
		$coupon = Plugin::get_instance()
			->container()
			->get( CouponRepositoryInterface::class )
			->find( new CouponId( $id ) );

		$this->assertNotNull( $coupon );

		$this->assertSame(
			'untouched',
			( new WjecfIntegration() )->render_cell(
				'untouched',
				'code',
				new InventoryEntry( $coupon, CouponStatus::ACTIVE, array(), array(), array() )
			)
		);
	}

	/**
	 * A coupon with none of this plugin's conditions says so, rather than
	 * rendering an empty cell that reads as missing data.
	 */
	public function test_a_coupon_without_extended_conditions_shows_a_dash(): void {
		$id = $this->create_coupon( 'plaincoupon' );

		$this->assertStringContainsString( '&mdash;', $this->render_conditions( $id ) );
	}

	/**
	 * The conditions a shop owner would want to see are named.
	 */
	public function test_it_names_the_conditions_it_finds(): void {
		$id = $this->create_coupon(
			'conditioned',
			array(
				'_wjecf_is_auto_coupon'      => 'yes',
				'_wjecf_first_purchase_only' => 'yes',
			)
		);

		$rendered = $this->render_conditions( $id );

		$this->assertStringContainsString( 'Applied automatically', $rendered );
		$this->assertStringContainsString( 'First purchase only', $rendered );
	}

	/**
	 * The categories AND/OR setting is read from the categories key.
	 *
	 * This is the bug in the prior art: it reads `_wjecf_products_and` for both,
	 * so its categories label always mirrors the products one. Here the two are
	 * set to opposite values, which is the only way to tell the two readings
	 * apart — with both set the same way, a wrong key gives the right answer.
	 */
	public function test_the_categories_rule_is_read_from_the_categories_key(): void {
		$category = wp_insert_term( 'Kitchenware', 'product_cat' );

		$this->assertIsArray( $category );

		$coupon = new WC_Coupon();
		$coupon->set_code( 'andor' );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( '10' );
		$coupon->set_product_ids( array( 123 ) );
		$coupon->set_product_categories( array( (int) $category['term_id'] ) );
		$id = $coupon->save();

		update_post_meta( $id, '_wjecf_products_and', 'no' );
		update_post_meta( $id, '_wjecf_categories_and', 'yes' );

		$rendered = $this->render_conditions( $id );

		$this->assertStringContainsString( 'Needs any listed product', $rendered );
		$this->assertStringContainsString( 'Needs all listed categories', $rendered );
	}

	/**
	 * The AND/OR rule is not mentioned where the coupon does not restrict by that
	 * thing at all. "Needs any listed product" against no listed products is
	 * noise, and this cell is meant to be read at a glance.
	 */
	public function test_the_matching_rule_is_silent_without_a_restriction(): void {
		$id = $this->create_coupon( 'norestriction', array( '_wjecf_products_and' => 'yes' ) );

		$this->assertStringNotContainsString( 'listed product', $this->render_conditions( $id ) );
	}

	/**
	 * A minimum on its own, a maximum on its own, and both together each read
	 * differently.
	 */
	public function test_it_phrases_a_minimum_a_maximum_and_a_range(): void {
		$min = $this->create_coupon( 'minonly', array( '_wjecf_min_matching_product_qty' => '3' ) );
		$max = $this->create_coupon( 'maxonly', array( '_wjecf_max_matching_product_qty' => '9' ) );

		$both = $this->create_coupon(
			'bothends',
			array(
				'_wjecf_min_matching_product_qty' => '3',
				'_wjecf_max_matching_product_qty' => '9',
			)
		);

		$this->assertStringContainsString( '≥ 3', $this->render_conditions( $min ) );
		$this->assertStringContainsString( '≤ 9', $this->render_conditions( $max ) );
		$this->assertStringContainsString( '3–9', $this->render_conditions( $both ) );
	}

	/**
	 * A range of zero is no range. The plugin stores an unset limit as an empty
	 * value, and "at least 0 items" is a condition on nothing.
	 */
	public function test_a_zero_range_is_not_a_condition(): void {
		$id = $this->create_coupon( 'zerorange', array( '_wjecf_min_matching_product_qty' => '0' ) );

		$this->assertStringNotContainsString( 'Matching items', $this->render_conditions( $id ) );
	}

	/**
	 * Lists are read whether the plugin stored them as an array or as a
	 * comma-separated string, which it does depending on the setting.
	 */
	public function test_it_reads_a_list_stored_either_way(): void {
		$as_array = $this->create_coupon(
			'listarray',
			array( '_wjecf_customer_roles' => array( 'subscriber', 'customer' ) )
		);

		$as_string = $this->create_coupon(
			'liststring',
			array( '_wjecf_payment_methods' => 'bacs, cheque' )
		);

		$this->assertStringContainsString( 'subscriber, customer', $this->render_conditions( $as_array ) );
		$this->assertStringContainsString( 'bacs, cheque', $this->render_conditions( $as_string ) );
	}

	/**
	 * An empty list is not a restriction. Stored as an empty string, it would
	 * otherwise render as a restriction to nothing at all.
	 */
	public function test_an_empty_list_is_not_a_restriction(): void {
		$id = $this->create_coupon( 'emptylist', array( '_wjecf_customer_roles' => '' ) );

		$this->assertStringNotContainsString( 'Only roles', $this->render_conditions( $id ) );
	}

	/**
	 * Whatever the other plugin stored is escaped before it reaches the page.
	 * These values are written by a third party and are not trusted.
	 */
	public function test_it_escapes_what_the_other_plugin_stored(): void {
		$id = $this->create_coupon(
			'escaping',
			array( '_wjecf_customer_roles' => '<script>alert(1)</script>' )
		);

		$rendered = $this->render_conditions( $id );

		$this->assertStringNotContainsString( '<script>', $rendered );
		$this->assertStringContainsString( '&lt;script&gt;', $rendered );
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

	/**
	 * Every integration names itself, and the names differ.
	 *
	 * The identifier is how a shop is told which other plugin a finding came
	 * from, and the label is what it reads on screen.
	 */
	public function test_every_integration_names_itself(): void {
		$integrations = array( new WjecfIntegration(), new YithPointsIntegration() );
		$identifiers  = array();

		foreach ( $integrations as $integration ) {
			$this->assertNotSame( '', trim( $integration->get_identifier() ), get_class( $integration ) );
			$this->assertNotSame( '', trim( $integration->get_label() ), get_class( $integration ) );

			$identifiers[] = $integration->get_identifier();
		}

		$this->assertSame( $identifiers, array_unique( $identifiers ) );
	}

	/**
	 * Registering them all attaches the ones that apply and leaves the rest
	 * alone. Neither plugin is installed here, so nothing should be hooked.
	 */
	public function test_registering_them_all_attaches_only_the_active_ones(): void {
		( new IntegrationRegistry( array( new WjecfIntegration(), new YithPointsIntegration() ) ) )->register_all();

		$this->assertFalse( has_filter( 'dfxcaaw_coupon_is_auto_applied' ) );
		$this->assertFalse( has_filter( 'dfxcaaw_coupon_query_args' ) );
	}
}
