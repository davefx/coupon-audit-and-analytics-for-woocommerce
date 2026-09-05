<?php
/**
 * WooCommerce's own cost of goods.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Cost;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use DFX\CouponAAW\Domain\Profit\Money;

/**
 * Cost of goods as recorded by WooCommerce itself, from 10.3 onwards.
 *
 * Availability comes from the public feature API. The value, however, has to be
 * read from the stored meta rather than from `get_cogs_value()`, and the reason
 * is worth stating: `has_cogs()` reports whether a line *type* supports a cost,
 * not whether one was recorded, and `get_cogs_value()` returns 0.0 for a line
 * that has none. Trusting the API alone would report full cost coverage and zero
 * costs for any store that switched the feature on without filling anything in —
 * a margin equal to revenue, stated with total confidence.
 *
 * Read straight from the line rather than through the order it belongs to. The
 * `has_cogs()` guard that used to stand in front of it always answered yes for a
 * product line, which is the only kind of line that ever reaches here, and
 * building an order to ask it cost five queries per order across a backfill.
 *
 * WooCommerce deletes the meta outright when the value is 0.0, so a genuinely
 * free line is indistinguishable from an unrecorded one and both read as
 * unknown. That imprecision is inherited from core's storage and cannot be
 * resolved from outside it; counting such a line as unknown is the conservative
 * direction, since §6.3 would rather report missing data than a wrong number.
 *
 * Highest priority of all the sources: where a store has switched core's feature
 * on, that is a deliberate act and core's own figures should win.
 */
final class NativeCogsSource implements CostSourceInterface {

	/**
     * @var string
     * @readonly
     */
    private string $currency;
    /**
     * @var int
     * @readonly
     */
    private int $decimals;
    /**
	 * The core feature this source depends on.
	 */
	private const FEATURE = 'cost_of_goods_sold';

	/**
	 * Where core stores a line's cost.
	 *
	 * Read directly because the public getter cannot distinguish a line with no
	 * cost from one costing nothing; see the note on this class.
	 */
	private const VALUE_META = '_cogs_value';

	/**
	 * Constructor.
	 *
	 * @param string $currency The store's currency.
	 * @param int    $decimals Places in the currency's minor unit.
	 */
	public function __construct(string $currency, int $decimals)
    {
        $this->currency = $currency;
        $this->decimals = $decimals;
    }

	/**
	 * Whether core's cost feature is switched on.
	 *
	 * It is registered with `enabled_by_default => false`, so most stores answer
	 * no here even on a WooCommerce new enough to offer it. That is the whole
	 * reason this plugin ships adapters for the third-party systems too.
	 */
	public function is_available(): bool {
		return class_exists( FeaturesUtil::class )
			&& FeaturesUtil::feature_is_enabled( self::FEATURE );
	}

	/**
	 * Stable machine name.
	 */
	public function get_identifier(): string {
		return 'woocommerce-native';
	}

	/**
	 * Human-readable name.
	 */
	public function get_label(): string {
		return __( 'WooCommerce (built-in cost of goods)', 'coupon-audit-and-analytics-for-woocommerce' );
	}

	/**
	 * What one order line cost.
	 *
	 * @param int $order_id     The order.
	 * @param int $line_item_id The line within it.
	 */
	public function get_line_cost( int $order_id, int $line_item_id ): ?Money {
		$stored = wc_get_order_item_meta( $line_item_id, self::VALUE_META );

		if ( ! is_numeric( $stored ) ) {
			return null;
		}

		return Money::from_decimal( (float) $stored, $this->currency, $this->decimals );
	}

	/**
	 * Core's figures win where the store has turned the feature on.
	 */
	public function get_priority(): int {
		return 10;
	}

	/**
	 * Core stores the cost on the order line, so it is the cost as of the sale.
	 */
	public function records_cost_at_sale(): bool {
		return true;
	}

	/**
	 * Warm the line meta.
	 *
	 * Core keeps its figure on the line item, so there is no product meta to
	 * warm and nothing else to read.
	 *
	 * @param list<int> $order_ids     The orders about to be asked about.
	 * @param list<int> $line_item_ids Every line of those orders.
	 */
	public function prime( array $order_ids, array $line_item_ids ): void {
		if ( array() !== $line_item_ids ) {
			update_meta_cache( 'order_item', $line_item_ids );
		}
	}
}
