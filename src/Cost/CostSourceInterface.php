<?php
/**
 * Cost source contract.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Cost;

use DFX\CouponAAW\Domain\Profit\Money;

/**
 * One way a store records what its goods cost (§7).
 *
 * WooCommerce only gained a cost field in 10.3, and it is off by default, so
 * most stores that track cost at all track it in a plugin they adopted years
 * earlier. One implementation per such system; adding another is meant to be
 * cheap, because this is the plugin's most likely extension point.
 */
interface CostSourceInterface {

	/**
	 * Whether this system is present and switched on in this store.
	 */
	public function is_available(): bool;

	/**
	 * Stable machine name, used to record which source a report came from and
	 * to remember the user's choice.
	 */
	public function get_identifier(): string;

	/**
	 * Human-readable name, for a settings screen.
	 */
	public function get_label(): string;

	/**
	 * What one order line cost, or null if this system has no figure for it.
	 *
	 * Null means "not known here" and must never be treated as zero: a line
	 * whose cost is unknown is what §6.3's coverage figure counts.
	 *
	 * @param int $order_id     The order.
	 * @param int $line_item_id The line within it.
	 */
	public function get_line_cost( int $order_id, int $line_item_id ): ?Money;

	/**
	 * Where this source sits when several are installed. Lower wins.
	 */
	public function get_priority(): int;

	/**
	 * Whether the figure is the cost recorded when the order was placed, rather
	 * than the product's cost today.
	 *
	 * Most third-party systems store only a product's current cost, so a margin
	 * built from them applies today's cost to an old order. That is a useful
	 * estimate and a poor fact, and §6.3 requires the difference be visible
	 * rather than assumed away.
	 */
	public function records_cost_at_sale(): bool;
}
