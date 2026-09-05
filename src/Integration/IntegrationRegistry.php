<?php
/**
 * Third-party integrations.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Integration;

/**
 * Activates the integrations whose plugins are actually present.
 *
 * Nothing is loaded speculatively: a shop without a given plugin pays nothing
 * for its support beyond one `is_active()` call.
 */
final class IntegrationRegistry {

	/**
     * @var list<IntegrationInterface>
     * @readonly
     */
    private array $integrations = array();
    /**
	 * Constructor.
	 *
	 * @param list<IntegrationInterface> $integrations Every integration this plugin knows.
	 */
	public function __construct(array $integrations = array())
    {
        $this->integrations = $integrations;
    }

	/**
	 * The integrations whose plugins are running.
	 *
	 * @return list<IntegrationInterface>
	 */
	public function active(): array {
		return array_values(
			array_filter(
				$this->integrations,
				static fn ( IntegrationInterface $integration ): bool => $integration->is_active()
			)
		);
	}

	/**
	 * Hook up everything that applies to this shop.
	 */
	public function register_all(): void {
		foreach ( $this->active() as $integration ) {
			$integration->register();
		}
	}
}
