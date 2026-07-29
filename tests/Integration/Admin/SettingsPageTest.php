<?php
/**
 * Settings screen tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Integration\Admin;

use DFX\CouponAAW\Admin\SettingsPage;
use DFX\CouponAAW\Cost\CostSourceRegistry;
use DFX\CouponAAW\Plugin;
use DFX\CouponAAW\Support\SettingsInterface;
use DFX\CouponAAW\Tests\Fixtures\FakeCostSource;
use WPDieException;
use WP_UnitTestCase;

/**
 * The one screen that writes what a visitor submitted.
 *
 * Everything else in the plugin reads. This posts a form back to itself, so the
 * checks around that — who may, whether the request was meant, and whether the
 * submitted value names anything real — are what these tests are about. A
 * tampered form must not be able to leave the plugin pointed at a cost system
 * that does not exist: §6.3 is explicit that no cost data beats wrong cost data,
 * and a dangling identifier would produce neither.
 */
final class SettingsPageTest extends WP_UnitTestCase {

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		$_POST = array();

		parent::tear_down();
	}

	/**
	 * Log in as somebody with the given role.
	 *
	 * @param string $role The role to assume.
	 */
	private function login_as( string $role ): void {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );

		$this->assertIsInt( $user_id );

		wp_set_current_user( $user_id );
	}

	/**
	 * Where the plugin's settings live.
	 */
	private function settings(): SettingsInterface {
		return Plugin::get_instance()->container()->get( SettingsInterface::class );
	}

	/**
	 * The screen, over a store that has one cost system to choose from.
	 *
	 * Built here rather than resolved from the container: the test store has no
	 * cost system at all — WooCommerce's own is off by default and no third-party
	 * plugin is installed — so a screen taken from the container would have
	 * nothing to choose between, and every question about choosing would be
	 * answered by the absence rather than by the logic.
	 */
	private function page(): SettingsPage {
		return new SettingsPage(
			$this->settings(),
			new CostSourceRegistry( array( new FakeCostSource( 'faketracker' ) ) )
		);
	}

	/**
	 * Render the screen and return its markup.
	 */
	private function render(): string {
		ob_start();

		try {
			$this->page()->render();
		} finally {
			// wp_die() throws out of render(), and an abandoned buffer makes
			// every later test in the process report as risky.
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * Submit the form as a shop manager would.
	 *
	 * @param array<string, string> $fields What the form carried.
	 * @param bool                  $signed Whether to include a valid nonce.
	 */
	private function submit( array $fields, bool $signed = true ): void {
		$this->login_as( 'administrator' );

		$_POST = $fields;

		if ( $signed ) {
			$_POST['_wpnonce'] = wp_create_nonce( 'dfxcaaw_save_settings' );
		}
	}

	/**
	 * Somebody without the capability is refused, rather than shown a form that
	 * will not save.
	 */
	public function test_rendering_is_refused_without_the_capability(): void {
		$this->login_as( 'subscriber' );

		$this->expectException( WPDieException::class );

		$this->render();
	}

	/**
	 * The form renders for somebody allowed to change things.
	 */
	public function test_it_renders_a_form(): void {
		$this->login_as( 'administrator' );

		$html = $this->render();

		$this->assertStringContainsString( '<form method="post"', $html );
		$this->assertStringContainsString( 'dfxcaaw_cost_source', $html );
	}

	/**
	 * A chosen cost system is stored.
	 */
	public function test_it_stores_a_chosen_cost_source(): void {
		$this->submit( array( 'dfxcaaw_cost_source' => 'faketracker' ) );

		$html = $this->render();

		$this->assertSame( 'faketracker', $this->settings()->get_string( 'cost_source' ) );
		$this->assertStringContainsString( 'Settings saved.', $html );
	}

	/**
	 * A request with no nonce is not a submission. Rendering the screen must not
	 * be enough to change what is stored.
	 */
	public function test_nothing_is_saved_without_a_nonce(): void {
		$this->settings()->set( 'cost_source', 'faketracker' );

		$this->submit( array( 'dfxcaaw_cost_source' => 'booster' ), signed: false );

		$html = $this->render();

		$this->assertSame( 'faketracker', $this->settings()->get_string( 'cost_source' ) );
		$this->assertStringNotContainsString( 'Settings saved.', $html );
	}

	/**
	 * Nor is one carrying a nonce that does not verify.
	 */
	public function test_nothing_is_saved_with_a_bad_nonce(): void {
		$this->settings()->set( 'cost_source', 'faketracker' );

		$this->login_as( 'administrator' );

		$_POST = array(
			'dfxcaaw_cost_source' => 'booster',
			'_wpnonce'            => 'not-a-real-nonce',
		);

		$this->render();

		$this->assertSame( 'faketracker', $this->settings()->get_string( 'cost_source' ) );
	}

	/**
	 * A submitted identifier that names no source this store has is not stored.
	 *
	 * The plugin would otherwise be pointed at a source that will never answer,
	 * and would report no cost data on a shop that plainly has some — which is
	 * the failure §6.3 says gets a plugin uninstalled.
	 */
	public function test_an_unknown_cost_source_is_refused(): void {
		$this->submit( array( 'dfxcaaw_cost_source' => 'a-plugin-that-does-not-exist' ) );

		$this->render();

		$this->assertNull(
			$this->settings()->get_string( 'cost_source' ),
			'A tampered form left the plugin pointed at a source that does not exist.'
		);
	}

	/**
	 * Choosing nothing means automatic selection, which is stored as an empty
	 * choice rather than as a missing setting.
	 */
	public function test_choosing_nothing_clears_the_choice(): void {
		$this->settings()->set( 'cost_source', 'faketracker' );

		$this->submit( array() );

		$this->render();

		$this->assertNull( $this->settings()->get_string( 'cost_source' ) );
	}

	/**
	 * The uninstall checkbox is stored by its presence, the way a checkbox
	 * submits — an unticked box sends nothing at all.
	 */
	public function test_the_uninstall_choice_follows_the_checkbox(): void {
		$this->submit( array( 'dfxcaaw_delete_on_uninstall' => '1' ) );
		$this->render();

		$this->assertTrue( $this->settings()->get( 'delete_data_on_uninstall' ) );

		$this->submit( array() );
		$this->render();

		$this->assertFalse(
			$this->settings()->get( 'delete_data_on_uninstall' ),
			'An unticked box left the previous choice in place.'
		);
	}

	/**
	 * The screen names the cost systems this store actually has, since choosing
	 * between them is the only reason to open it.
	 */
	public function test_it_lists_the_available_cost_sources(): void {
		$this->login_as( 'administrator' );

		$this->assertStringContainsString( 'value="faketracker"', $this->render() );
	}
}
