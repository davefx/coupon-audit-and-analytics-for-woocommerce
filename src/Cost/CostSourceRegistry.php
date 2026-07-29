<?php
/**
 * Cost source selection.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Cost;

use InvalidArgumentException;

/**
 * Chooses the one cost source a report is built from.
 *
 * §7 describes walking the available adapters in priority order and returning
 * the first that yields a value. Done per line, that would read one plugin's
 * cost for one line and a different plugin's for the next, and the resulting
 * margin would be a blend of two bookkeeping systems that reconciles with
 * neither. A store manages cost in one place, so exactly one source is selected
 * here and only that source is read.
 *
 * A line the active source has no figure for is a line with unknown cost. It is
 * never filled in from elsewhere — which is what makes §6.3's coverage figure
 * mean something, since it then measures one system's completeness rather than
 * the union of several.
 */
final class CostSourceRegistry {

	/**
	 * Every registered source, in priority order.
	 *
	 * @var list<CostSourceInterface>
	 */
	private readonly array $sources;

	/**
	 * Constructor.
	 *
	 * @param list<CostSourceInterface> $sources The registered sources, in any order.
	 * @param string|null               $chosen  Identifier the user picked, if any.
	 *
	 * @throws InvalidArgumentException When two sources claim the same identifier.
	 */
	public function __construct( array $sources, private readonly ?string $chosen = null ) {
		$seen = array();

		foreach ( $sources as $source ) {
			$identifier = $source->get_identifier();

			if ( isset( $seen[ $identifier ] ) ) {
				throw new InvalidArgumentException(
					sprintf(
						'Two cost sources claim the identifier "%s"; a report must be able to say where its figures came from.',
						$identifier
					)
				);
			}

			$seen[ $identifier ] = true;
		}

		usort(
			$sources,
			static fn ( CostSourceInterface $a, CostSourceInterface $b ): int
				=> $a->get_priority() <=> $b->get_priority()
		);

		$this->sources = $sources;
	}

	/**
	 * Every source present in this store, in priority order.
	 *
	 * @return list<CostSourceInterface>
	 */
	public function available(): array {
		return array_values(
			array_filter(
				$this->sources,
				static fn ( CostSourceInterface $source ): bool => $source->is_available()
			)
		);
	}

	/**
	 * The single source every figure in a report comes from.
	 *
	 * The user's choice wins where it is still installed — §7 is explicit that
	 * where two cost systems coexist the user decides, because only they know
	 * which one their bookkeeping actually uses. A choice naming a plugin since
	 * deactivated falls back to priority rather than reporting nothing: the
	 * store still has cost data, it just is not where the setting says.
	 */
	public function active(): ?CostSourceInterface {
		$available = $this->available();

		if ( array() === $available ) {
			return null;
		}

		if ( null !== $this->chosen ) {
			foreach ( $available as $source ) {
				if ( $this->chosen === $source->get_identifier() ) {
					return $source;
				}
			}
		}

		return $available[0];
	}
}
