<?php
/**
 * Margin screen integration tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Integration\Admin;

use DateTimeImmutable;
use DFX\CouponAAW\Admin\MarginPage;
use DFX\CouponAAW\Admin\MenuRegistrar;
use DFX\CouponAAW\Domain\Coupon\CouponId;
use DFX\CouponAAW\Domain\Profit\CouponDayStats;
use DFX\CouponAAW\Domain\Profit\Money;
use DFX\CouponAAW\Install\SchemaMigrator;
use DFX\CouponAAW\Plugin;
use DFX\CouponAAW\Support\SettingsInterface;
use DFX\CouponAAW\Repository\CouponStatsRepositoryInterface;
use WC_Coupon;
use WP_UnitTestCase;

/**
 * §6.3's three states, as a reader actually meets them.
 *
 * The hardest thing this screen does is say "I do not know" convincingly, and
 * that is what is asserted here: no cost data means no margin and an
 * explanation, partial cost data means a margin labelled an estimate.
 */
final class MarginPageTest extends WP_UnitTestCase {

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		Plugin::get_instance()->container()->get( SchemaMigrator::class )->migrate();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertIsInt( $user_id );
		wp_set_current_user( $user_id );
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		Plugin::get_instance()->container()->get( SchemaMigrator::class )->drop();
		delete_option( 'dfxcaaw_settings' );

		parent::tear_down();
	}

	/**
	 * Store a day's figures against a real coupon.
	 *
	 * @param int $revenue       Net revenue in minor units.
	 * @param int $cost          Cost in minor units.
	 * @param int $covered_lines Lines whose cost is known.
	 * @param int $total_lines   Lines in total.
	 */
	private function store( int $revenue, int $cost, int $covered_lines, int $total_lines ): void {
		$coupon = new WC_Coupon();
		$coupon->set_code( 'summersale' );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( '10' );
		$id = $coupon->save();

		$today    = ( new DateTimeImmutable( 'now', wp_timezone() ) )->setTime( 0, 0 );
		$currency = get_woocommerce_currency();

		Plugin::get_instance()->container()->get( CouponStatsRepositoryInterface::class )->replace_day(
			$today,
			array(
				new CouponDayStats(
					new CouponId( $id ),
					$today,
					3,
					new Money( $revenue, $currency ),
					new Money( 500, $currency ),
					new Money( $cost, $currency ),
					$covered_lines,
					$total_lines
				),
			),
			'fake'
		);
	}

	/**
	 * Render the screen.
	 */
	private function render(): string {
		ob_start();

		try {
			Plugin::get_instance()->container()->get( MarginPage::class )->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * The screen is registered under WooCommerce alongside the audit.
	 */
	public function test_the_margin_screen_has_its_own_menu_entry(): void {
		$GLOBALS['submenu'] = array();

		Plugin::get_instance()->container()->get( MenuRegistrar::class )->register();

		$menus = $GLOBALS['submenu'];
		$this->assertIsArray( $menus );
		$this->assertArrayHasKey( 'woocommerce', $menus );

		$this->assertContains( MarginPage::PAGE_SLUG, array_column( $menus['woocommerce'], 2 ) );
	}

	/**
	 * With full cost data, the margin is shown plainly.
	 */
	public function test_full_coverage_shows_a_margin_without_caveat(): void {
		$this->store( 10000, 4000, 4, 4 );

		$html = $this->render();

		$this->assertStringContainsString( 'summersale', $html );
		$this->assertStringNotContainsString( 'estimate', $html );
		$this->assertStringNotContainsString( 'Needs cost data', $html );
	}

	/**
	 * With partial cost data the margin appears, labelled as an estimate and
	 * with the covered share stated (§6.3).
	 */
	public function test_partial_coverage_is_labelled_an_estimate(): void {
		$this->store( 10000, 2000, 1, 4 );

		$html = $this->render();

		$this->assertStringContainsString( 'estimate', $html );
		$this->assertStringContainsString( '25%', $html );
	}

	/**
	 * With no cost data at all, no margin is shown and the screen says what to
	 * do about it. Revenue dressed up as profit is the failure §6.3 exists to
	 * prevent.
	 */
	public function test_no_coverage_shows_no_margin_and_explains_why(): void {
		$this->store( 10000, 0, 0, 4 );

		$html = $this->render();

		$this->assertStringContainsString( 'Needs cost data', $html );
		$this->assertStringContainsString( 'No margin can be shown yet', $html );
	}

	/**
	 * An empty period says so rather than looking broken.
	 */
	public function test_a_period_without_coupons_says_so(): void {
		$this->assertStringContainsString( 'No coupon was used in this period.', $this->render() );
	}

	/**
	 * The screen refuses a user without the capability, as §14 requires of
	 * every admin entry point.
	 */
	public function test_it_refuses_a_user_without_the_capability(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertIsInt( $user_id );
		wp_set_current_user( $user_id );

		$this->expectException( \WPDieException::class );

		$this->render();
	}

	/**
	 * While the backfill is still walking the store's history, the screen says
	 * how far it has got.
	 *
	 * The figures are genuinely incomplete until it finishes, and a shop reading
	 * a half-filled report as a finished one would conclude its coupons earned
	 * less than they did.
	 */
	public function test_it_says_when_the_figures_are_still_being_built(): void {
		$this->store( 10000, 4000, 4, 4 );

		Plugin::get_instance()->container()->get( SettingsInterface::class )->set( 'backfill_cursor', '2026-03-01' );

		$html = $this->render();

		$this->assertStringContainsString( 'Still reading past orders', $html );
		$this->assertStringContainsString( '2026-03-01', $html );
	}

	/**
	 * Once it has finished, it stops saying so.
	 */
	public function test_a_finished_backfill_is_not_announced(): void {
		$this->store( 10000, 4000, 4, 4 );

		$settings = Plugin::get_instance()->container()->get( SettingsInterface::class );
		$settings->set( 'backfill_cursor', '2026-03-01' );
		$settings->set( 'backfill_complete', true );

		$this->assertStringNotContainsString( 'Still reading past orders', $this->render() );
	}

	/**
	 * Store two coupons for today, with the coverage each is given.
	 *
	 * @param int $first_covered  Covered lines for the first coupon.
	 * @param int $second_covered Covered lines for the second.
	 */
	private function store_two( int $first_covered, int $second_covered ): void {
		$today    = ( new DateTimeImmutable( 'now', wp_timezone() ) )->setTime( 0, 0 );
		$currency = get_woocommerce_currency();
		$rows     = array();

		foreach ( array( $first_covered, $second_covered ) as $index => $covered ) {
			$coupon = new WC_Coupon();
			$coupon->set_code( 'mixed' . $index );
			$coupon->set_discount_type( 'percent' );
			$coupon->set_amount( '10' );

			$rows[] = new CouponDayStats(
				new CouponId( $coupon->save() ),
				$today,
				1,
				new Money( 10000, $currency ),
				new Money( 500, $currency ),
				new Money( 4000, $currency ),
				$covered,
				4
			);
		}

		Plugin::get_instance()
			->container()
			->get( CouponStatsRepositoryInterface::class )
			->replace_day( $today, $rows, 'fake' );
	}

	/**
	 * Where one coupon has cost behind it and another has none, the screen says
	 * so rather than leaving the blank to be read as a zero margin.
	 */
	public function test_it_explains_partly_missing_cost_data(): void {
		$this->store_two( 4, 0 );

		$this->assertStringContainsString( 'no cost recorded', $this->render() );
	}

	/**
	 * Where every coupon has cost behind it, there is nothing to explain and the
	 * caveat stays off the screen.
	 */
	public function test_full_coverage_carries_no_caveat(): void {
		$this->store_two( 4, 4 );

		$this->assertStringNotContainsString( 'no cost recorded', $this->render() );
	}
}
