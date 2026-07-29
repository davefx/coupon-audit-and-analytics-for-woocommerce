<?php
/**
 * Container unit tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Container;

use DFX\CouponAAW\Container\Container;
use DFX\CouponAAW\Container\ContainerInterface;
use DFX\CouponAAW\Container\Exception\CircularDependencyException;
use DFX\CouponAAW\Container\Exception\ServiceNotFoundException;
use DFX\CouponAAW\Tests\Fixtures\Container\DependentService;
use DFX\CouponAAW\Tests\Fixtures\Container\DummyService;
use PHPUnit\Framework\TestCase;

/**
 * The container is one of only two singletons in the codebase (§3.3). Every
 * behaviour that makes it safe to use under TDD — overridable bindings above
 * all — is pinned down here.
 */
final class ContainerTest extends TestCase {

	/**
	 * The subject under test. A fresh instance per test, never the singleton.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = new Container();
	}

	/**
	 * A bound factory is invoked and its return value handed back.
	 */
	public function test_it_resolves_a_bound_service(): void {
		$this->container->bind( DummyService::class, static fn (): DummyService => new DummyService() );

		$this->assertInstanceOf( DummyService::class, $this->container->get( DummyService::class ) );
	}

	/**
	 * The factory receives the container, so a service can resolve its own
	 * dependencies without knowing where they came from.
	 */
	public function test_it_passes_itself_to_the_factory(): void {
		$this->container->bind( DummyService::class, static fn (): DummyService => new DummyService() );
		$this->container->bind(
			DependentService::class,
			static fn ( ContainerInterface $container ): DependentService => new DependentService(
				$container->get( DummyService::class )
			)
		);

		$resolved = $this->container->get( DependentService::class );

		$this->assertInstanceOf( DependentService::class, $resolved );
		$this->assertSame( $this->container->get( DummyService::class ), $resolved->dependency );
	}

	/**
	 * Bindings are shared: the factory runs once and the result is cached.
	 */
	public function test_it_caches_a_bound_service(): void {
		$calls = 0;
		$this->container->bind(
			DummyService::class,
			static function () use ( &$calls ): DummyService {
				++$calls;
				return new DummyService();
			}
		);

		$first  = $this->container->get( DummyService::class );
		$second = $this->container->get( DummyService::class );

		$this->assertSame( $first, $second );
		$this->assertSame( 1, $calls );
	}

	/**
	 * A factory binding is explicitly not cached.
	 */
	public function test_it_does_not_cache_a_factory_binding(): void {
		$this->container->factory( DummyService::class, static fn (): DummyService => new DummyService() );

		$this->assertNotSame(
			$this->container->get( DummyService::class ),
			$this->container->get( DummyService::class )
		);
	}

	/**
	 * A pre-built object can be handed to the container. This is how a test
	 * double replaces a real collaborator.
	 */
	public function test_it_accepts_a_prebuilt_instance(): void {
		$double = new DummyService( 'double' );

		$this->container->instance( DummyService::class, $double );

		$this->assertSame( $double, $this->container->get( DummyService::class ) );
	}

	/**
	 * Re-binding an identifier replaces the previous definition. Without this
	 * the container could not be reconfigured by a test.
	 */
	public function test_rebinding_replaces_the_previous_definition(): void {
		$this->container->bind( DummyService::class, static fn (): DummyService => new DummyService( 'first' ) );
		$this->container->bind( DummyService::class, static fn (): DummyService => new DummyService( 'second' ) );

		$this->assertSame( 'second', $this->container->get( DummyService::class )->name );
	}

	/**
	 * Re-binding after resolution discards the cached instance, so an override
	 * applied late still takes effect.
	 */
	public function test_rebinding_discards_an_already_resolved_instance(): void {
		$this->container->bind( DummyService::class, static fn (): DummyService => new DummyService( 'first' ) );
		$this->container->get( DummyService::class );

		$this->container->instance( DummyService::class, new DummyService( 'double' ) );

		$this->assertSame( 'double', $this->container->get( DummyService::class )->name );
	}

	/**
	 * Membership reporting covers both factories and pre-built instances.
	 */
	public function test_has_reports_whether_an_identifier_is_bound(): void {
		$this->assertFalse( $this->container->has( DummyService::class ) );

		$this->container->bind( DummyService::class, static fn (): DummyService => new DummyService() );

		$this->assertTrue( $this->container->has( DummyService::class ) );
	}

	/**
	 * Resolving something that was never bound is a programming error, not a
	 * null return.
	 *
	 * This used to assert that the message named the missing service, which was
	 * more useful. WordPress.Security.EscapeOutput rejects any variable reaching
	 * an exception constructor, so the message is now fixed and the identifier
	 * lives in the stack trace instead.
	 */
	public function test_it_throws_when_resolving_an_unbound_identifier(): void {
		$this->expectException( ServiceNotFoundException::class );

		$this->container->get( DummyService::class );
	}

	/**
	 * A dependency cycle is reported rather than exhausting the stack.
	 *
	 * The message used to spell the chain out. It no longer can, for the reason
	 * given on the test above; the chain is still readable in the stack trace,
	 * one frame per service being resolved.
	 */
	public function test_it_detects_a_circular_dependency(): void {
		$this->container->bind( 'a', static fn ( ContainerInterface $c ): mixed => $c->get( 'b' ) );
		$this->container->bind( 'b', static fn ( ContainerInterface $c ): mixed => $c->get( 'a' ) );

		$this->expectException( CircularDependencyException::class );

		$this->container->get( 'a' );
	}

	/**
	 * A failed resolution leaves no trace: the same identifier can be resolved
	 * again once the cycle is broken.
	 */
	public function test_a_failed_resolution_does_not_poison_later_ones(): void {
		$this->container->bind( 'a', static fn ( ContainerInterface $c ): mixed => $c->get( 'b' ) );
		$this->container->bind( 'b', static fn ( ContainerInterface $c ): mixed => $c->get( 'a' ) );

		try {
			$this->container->get( 'a' );
		} catch ( CircularDependencyException ) {
			$this->container->bind( 'b', static fn (): string => 'resolved' );
		}

		$this->assertSame( 'resolved', $this->container->get( 'a' ) );
	}

	/**
	 * The singleton accessor always hands back the same canonical container.
	 */
	public function test_get_instance_returns_the_canonical_container(): void {
		$this->assertSame( Container::get_instance(), Container::get_instance() );
	}

	/**
	 * The canonical container is not the only one that can exist: tests build
	 * their own, which is what keeps the singleton harmless (§3.3).
	 */
	public function test_the_canonical_container_is_not_forced_on_callers(): void {
		$this->assertNotSame( Container::get_instance(), $this->container );
	}
}
