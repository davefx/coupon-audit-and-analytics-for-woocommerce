<?php
/**
 * Margin window tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Integration\Service;

use DFX\CouponAAW\Container\Container;
use DFX\CouponAAW\Providers\AdminServiceProvider;
use DFX\CouponAAW\Providers\CoreServiceProvider;
use DFX\CouponAAW\Service\MarginService;
use DFX\CouponAAW\Support\PluginContext;
use WP_UnitTestCase;

/**
 * How far back the margin screen looks, and who gets to say.
 *
 * Thirty days is the default, not an allowance. It is a filter rather than a
 * setting because the shops that want a different window mostly want it decided
 * by something — a reporting period, another plugin, a snippet in a theme — and
 * a filter serves all of those without a screen.
 *
 * The filter is public and documented, which is the whole point: anything may
 * change it, for free, including the site's own `functions.php`. Nothing here is
 * withheld pending payment.
 */
final class MarginWindowTest extends WP_UnitTestCase {

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		remove_all_filters( 'dfxcaaw_margin_window_days' );

		parent::tear_down();
	}

	/**
	 * A service built through a container of its own.
	 *
	 * Its own, rather than the plugin's: a container keeps what it built, and the
	 * window is settled when the service is constructed — so a shared one would
	 * answer every test with whatever the first of them asked for.
	 */
	private function service(): MarginService {
		$container = new Container();

		$context = new PluginContext( '0.0.0', __FILE__, __DIR__ . '/', 'http://example.test/', 'dfxcaaw-test' );

		( new CoreServiceProvider( $context, wp_timezone() ) )->register( $container );
		( new AdminServiceProvider() )->register( $container );

		$resolved = $container->get( MarginService::class );

		$this->assertInstanceOf( MarginService::class, $resolved );

		return $resolved;
	}

	/**
	 * Thirty days unless something says otherwise.
	 */
	public function test_the_window_is_thirty_days_by_default(): void {
		$this->assertSame( 30, $this->service()->window_days() );
	}

	/**
	 * And whatever a filter says when one is attached.
	 */
	public function test_a_filter_can_widen_the_window(): void {
		add_filter( 'dfxcaaw_margin_window_days', static fn (): int => 365 );

		$this->assertSame( 365, $this->service()->window_days() );
	}

	/**
	 * The filter is given the default, so a caller can reason from it rather than
	 * repeating the number.
	 */
	public function test_the_filter_receives_the_default(): void {
		$seen = null;

		add_filter(
			'dfxcaaw_margin_window_days',
			static function ( $days ) use ( &$seen ) {
				$seen = $days;

				return $days;
			}
		);

		$this->service();

		$this->assertSame( 30, $seen );
	}

	/**
	 * A nonsensical window is refused rather than obeyed. A filter returning zero
	 * or a negative number would otherwise produce a period that ends before it
	 * starts, and a screen full of nothing.
	 */
	public function test_a_window_of_less_than_a_day_falls_back_to_the_default(): void {
		add_filter( 'dfxcaaw_margin_window_days', static fn (): int => 0 );

		$this->assertSame( 30, $this->service()->window_days() );
	}

	/**
	 * Nor does a filter returning something that is not a number at all.
	 */
	public function test_a_window_that_is_not_a_number_falls_back_to_the_default(): void {
		add_filter( 'dfxcaaw_margin_window_days', static fn (): string => 'a fortnight' );

		$this->assertSame( 30, $this->service()->window_days() );
	}
}
