<?php
/**
 * A cost source that answers from an array.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Fixtures;

use DFX\CouponAAW\Cost\CostSourceInterface;
use DFX\CouponAAW\Domain\Profit\Money;

/**
 * The cost seam of §10.4, filled in for the unit suite.
 */
final class FakeCostSource implements CostSourceInterface {

	/**
	 * Constructor.
	 *
	 * @param string               $identifier   Stable machine name.
	 * @param bool                 $available    Whether the source reports itself installed.
	 * @param int                  $priority     Lower wins.
	 * @param array<string, Money> $costs        Costs keyed by "order:line".
	 * @param bool                 $at_sale      Whether it records cost as of the sale.
	 */
	public function __construct(
		private readonly string $identifier,
		private readonly bool $available = true,
		private readonly int $priority = 10,
		private readonly array $costs = array(),
		private readonly bool $at_sale = true
	) {}

	/**
	 * Whether the source is installed.
	 */
	public function is_available(): bool {
		return $this->available;
	}

	/**
	 * Stable machine name.
	 */
	public function get_identifier(): string {
		return $this->identifier;
	}

	/**
	 * Human-readable name.
	 */
	public function get_label(): string {
		return ucfirst( $this->identifier );
	}

	/**
	 * The cost of one line.
	 *
	 * @param int $order_id     The order.
	 * @param int $line_item_id The line within it.
	 */
	public function get_line_cost( int $order_id, int $line_item_id ): ?Money {
		return $this->costs[ $order_id . ':' . $line_item_id ] ?? null;
	}

	/**
	 * Lower wins.
	 */
	public function get_priority(): int {
		return $this->priority;
	}

	/**
	 * Whether the cost is the one recorded at the time of sale.
	 */
	public function records_cost_at_sale(): bool {
		return $this->at_sale;
	}
}
