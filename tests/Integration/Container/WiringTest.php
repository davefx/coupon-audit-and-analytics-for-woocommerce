<?php
/**
 * Container wiring tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Integration\Container;

use DFX\CouponAAW\Admin\CouponEditorNotices;
use DFX\CouponAAW\Admin\CouponTermsFormatter;
use DFX\CouponAAW\Admin\InventoryListTable;
use DFX\CouponAAW\Admin\InventoryPage;
use DFX\CouponAAW\Admin\MarginListTable;
use DFX\CouponAAW\Admin\MarginPage;
use DFX\CouponAAW\Admin\MenuRegistrar;
use DFX\CouponAAW\Admin\SettingsPage;
use DFX\CouponAAW\Catalog\CatalogRepositoryInterface;
use DFX\CouponAAW\Cost\CostSourceRegistry;
use DFX\CouponAAW\Domain\Clock\ClockInterface;
use DFX\CouponAAW\Domain\Coupon\ConfigurationAuditor;
use DFX\CouponAAW\Domain\Coupon\OrphanDetector;
use DFX\CouponAAW\Domain\Coupon\StatusResolver;
use DFX\CouponAAW\Domain\Overlap\OverlapDetector;
use DFX\CouponAAW\Install\Aggregator;
use DFX\CouponAAW\Install\SchemaMigrator;
use DFX\CouponAAW\Integration\IntegrationRegistry;
use DFX\CouponAAW\Plugin;
use DFX\CouponAAW\Repository\CouponRepositoryInterface;
use DFX\CouponAAW\Repository\CouponStatsRepositoryInterface;
use DFX\CouponAAW\Repository\OrderStatsRepositoryInterface;
use DFX\CouponAAW\Service\AggregationInterface;
use DFX\CouponAAW\Service\InventoryService;
use DFX\CouponAAW\Service\MarginService;
use DFX\CouponAAW\Service\PrePublishValidator;
use DFX\CouponAAW\Support\SettingsInterface;
use WP_UnitTestCase;

/**
 * Every service the plugin registers can actually be built.
 *
 * A container hides its mistakes until something asks for the thing that was
 * wired wrongly, and the admin screens are resolved inside hook callbacks — so a
 * typo in a binding shows up as a fatal error on somebody's dashboard rather
 * than in a test run. Resolving the lot here moves that to now.
 *
 * The list is written out by hand on purpose. Iterating whatever the container
 * happens to hold would pass just as happily on a container that had lost half
 * its bindings.
 */
final class WiringTest extends WP_UnitTestCase {

	/**
	 * Every service the plugin is expected to be able to build.
	 *
	 * @return array<string, array{class-string}>
	 */
	public function provide_services(): array {
		$services = array(
			ClockInterface::class,
			SettingsInterface::class,
			SchemaMigrator::class,
			StatusResolver::class,
			OrphanDetector::class,
			OverlapDetector::class,
			ConfigurationAuditor::class,
			CouponRepositoryInterface::class,
			CouponStatsRepositoryInterface::class,
			OrderStatsRepositoryInterface::class,
			CatalogRepositoryInterface::class,
			CostSourceRegistry::class,
			IntegrationRegistry::class,
			AggregationInterface::class,
			Aggregator::class,
			InventoryService::class,
			MarginService::class,
			PrePublishValidator::class,
			CouponTermsFormatter::class,
			InventoryListTable::class,
			MarginListTable::class,
			InventoryPage::class,
			MarginPage::class,
			SettingsPage::class,
			MenuRegistrar::class,
			CouponEditorNotices::class,
		);

		$cases = array();

		foreach ( $services as $service ) {
			$cases[ $service ] = array( $service );
		}

		return $cases;
	}

	/**
	 * Each one resolves to an instance of what it promises.
	 *
	 * @dataProvider provide_services
	 *
	 * @param class-string $service The service to resolve.
	 */
	public function test_the_container_can_build_it( string $service ): void {
		$resolved = Plugin::get_instance()->container()->get( $service );

		$this->assertInstanceOf( $service, $resolved );
	}

	/**
	 * Asking twice gets the same instance.
	 *
	 * The two singletons are the plugin and the container (§5); everything else
	 * is shared only in the sense that the container hands back what it built.
	 * A service built afresh each time would quietly double the work on screens
	 * that resolve the same thing more than once.
	 */
	public function test_a_service_is_built_once(): void {
		$container = Plugin::get_instance()->container();

		$this->assertSame(
			$container->get( InventoryService::class ),
			$container->get( InventoryService::class )
		);
	}
}
