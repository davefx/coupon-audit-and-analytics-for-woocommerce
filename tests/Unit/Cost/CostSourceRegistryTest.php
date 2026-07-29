<?php
/**
 * Cost source registry unit tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Cost;

use DFX\CouponAAW\Cost\CostSourceRegistry;
use DFX\CouponAAW\Domain\Profit\Money;
use InvalidArgumentException;
use DFX\CouponAAW\Tests\Fixtures\FakeCostSource;
use PHPUnit\Framework\TestCase;

/**
 * Chooses the one source a report is built from (§7).
 *
 * The rule this class exists to enforce: a report never mixes cost from two
 * different plugins. §7 describes walking the adapters and taking the first that
 * yields a value, but done per line that would read one plugin's cost for one
 * line and another's for the next, producing a margin that is a blend of two
 * bookkeeping systems and reconciles with neither. A store manages cost in one
 * place, so exactly one source is chosen and only that source is read.
 */
final class CostSourceRegistryTest extends TestCase {

	/**
	 * With nothing installed there is no cost data and no margin to show.
	 */
	public function test_an_empty_registry_has_no_active_source(): void {
		$registry = new CostSourceRegistry( array() );

		$this->assertNull( $registry->active() );
		$this->assertSame( array(), $registry->available() );
	}

	/**
	 * A source that is not installed is never chosen.
	 */
	public function test_an_unavailable_source_is_never_chosen(): void {
		$registry = new CostSourceRegistry( array( new FakeCostSource( 'absent', false ) ) );

		$this->assertNull( $registry->active() );
	}

	/**
	 * The only installed source is the one used.
	 */
	public function test_the_only_available_source_is_chosen(): void {
		$registry = new CostSourceRegistry( array( new FakeCostSource( 'native' ) ) );

		$this->assertSame( 'native', $registry->active()?->get_identifier() );
	}

	/**
	 * With several installed, priority decides. Lower wins, so native COGS can
	 * be preferred over a third-party plugin without either knowing about the
	 * other.
	 */
	public function test_priority_decides_between_available_sources(): void {
		$registry = new CostSourceRegistry(
			array(
				new FakeCostSource( 'third-party', true, 20 ),
				new FakeCostSource( 'native', true, 10 ),
			)
		);

		$this->assertSame( 'native', $registry->active()?->get_identifier() );
	}

	/**
	 * The user's choice beats priority. §7 is explicit that where two cost
	 * systems coexist the user decides which one wins, because only they know
	 * which of the two their bookkeeping actually uses.
	 */
	public function test_the_users_choice_beats_priority(): void {
		$registry = new CostSourceRegistry(
			array(
				new FakeCostSource( 'native', true, 10 ),
				new FakeCostSource( 'wpfactory', true, 20 ),
			),
			'wpfactory'
		);

		$this->assertSame( 'wpfactory', $registry->active()?->get_identifier() );
	}

	/**
	 * A choice naming a plugin that has since been deactivated falls back to
	 * priority rather than reporting nothing. The store still has cost data; it
	 * just is not where the setting says.
	 */
	public function test_a_choice_that_is_no_longer_available_falls_back(): void {
		$registry = new CostSourceRegistry(
			array( new FakeCostSource( 'native', true, 10 ) ),
			'wpfactory'
		);

		$this->assertSame( 'native', $registry->active()?->get_identifier() );
	}

	/**
	 * A choice naming something that was never registered is ignored.
	 */
	public function test_a_choice_naming_an_unknown_source_is_ignored(): void {
		$registry = new CostSourceRegistry(
			array( new FakeCostSource( 'native', true, 10 ) ),
			'something-else'
		);

		$this->assertSame( 'native', $registry->active()?->get_identifier() );
	}

	/**
	 * Everything installed is listed, in priority order, so a settings screen
	 * can offer the choice.
	 */
	public function test_it_lists_available_sources_in_priority_order(): void {
		$registry = new CostSourceRegistry(
			array(
				new FakeCostSource( 'third', true, 30 ),
				new FakeCostSource( 'absent', false, 1 ),
				new FakeCostSource( 'first', true, 10 ),
				new FakeCostSource( 'second', true, 20 ),
			)
		);

		$this->assertSame(
			array( 'first', 'second', 'third' ),
			array_map(
				static fn ( $source ): string => $source->get_identifier(),
				$registry->available()
			)
		);
	}

	/**
	 * The whole point, stated as a test: a line with no cost in the active
	 * source is missing cost. It is never filled in from another plugin, even
	 * when that plugin is installed and has a value for it. Blending them would
	 * produce a total that reconciles with neither system's books.
	 */
	public function test_a_missing_cost_is_never_filled_in_from_another_source(): void {
		$registry = new CostSourceRegistry(
			array(
				new FakeCostSource( 'native', true, 10, array() ),
				new FakeCostSource( 'wpfactory', true, 20, array( '5:9' => new Money( 500, 'EUR' ) ) ),
			)
		);

		$active = $registry->active();

		$this->assertNotNull( $active );
		$this->assertSame( 'native', $active->get_identifier() );
		$this->assertNull(
			$active->get_line_cost( 5, 9 ),
			'The line has a cost in wpfactory, and that must not leak into a native report.'
		);
	}

	/**
	 * Two sources cannot claim the same identifier; a report has to be able to
	 * name where its numbers came from without ambiguity.
	 */
	public function test_it_rejects_two_sources_with_the_same_identifier(): void {
		$this->expectException( InvalidArgumentException::class );

		new CostSourceRegistry(
			array( new FakeCostSource( 'native' ), new FakeCostSource( 'native' ) )
		);
	}
}
