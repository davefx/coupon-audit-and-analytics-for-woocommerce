<?php
/**
 * Cost read from a product meta key.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Cost;

use DFX\CouponAAW\Domain\Profit\Money;
use WC_Abstract_Order;
use WC_Order_Item_Product;

/**
 * Shared behaviour for the cost plugins that store a per-product figure.
 *
 * Most of them do. A line's cost is then that product's cost multiplied by the
 * quantity ordered — which means it is the cost *today*, not the cost when the
 * order was placed. For a store whose supplier prices are stable that is close
 * to exact; for one whose costs move it is an estimate, and
 * `records_cost_at_sale()` says so rather than letting the screen imply
 * otherwise.
 *
 * Variations are looked at before their parent: a variation with its own cost
 * is the more specific answer, and falling straight to the parent would report
 * the same cost for a small and an extra-large.
 */
abstract class ProductMetaCostSource implements CostSourceInterface {

	/**
	 * Constructor.
	 *
	 * @param string $currency The store's currency.
	 * @param int    $decimals Places in the currency's minor unit.
	 */
	public function __construct(
		protected readonly string $currency,
		protected readonly int $decimals
	) {}

	/**
	 * The meta key this system keeps a product's unit cost under.
	 */
	abstract protected function cost_meta_key(): string;

	/**
	 * Whether this system's own plugin is installed and switched on.
	 *
	 * Its presence is a good signal and a poor requirement, which is why it is
	 * only half of is_available() below.
	 */
	abstract protected function plugin_is_present(): bool;

	/**
	 * Whether this shop's cost is readable through this system.
	 *
	 * The plugin if it is here, and otherwise the data. Asking only about the
	 * plugin gets it wrong in every direction that matters: a shop that migrated
	 * away and kept its data, a shop whose own snippet writes the same key —
	 * which is what prompted this — and a shop that installed the plugin, never
	 * configured it, and has nothing to read.
	 *
	 * A shop cannot answer this question itself. It sees cost on its products
	 * and a margin screen saying there is none, and §6.3 is explicit that this
	 * is the failure that gets the plugin uninstalled rather than reported.
	 */
	public function is_available(): bool {
		return $this->plugin_is_present() || $this->cost_is_recorded();
	}

	/**
	 * Whether any product carries a cost under this system's key.
	 *
	 * One indexed lookup, remembered for the hour. meta_key is indexed and the
	 * query stops at the first row, so it stays cheap on a large catalogue — but
	 * "cheap" is not "free", and this is asked while building a settings screen
	 * that may consult every registered source.
	 *
	 * An empty string is not cost: a plugin installed and never configured
	 * leaves its key on every product with nothing in it, and reading that as
	 * "this shop tracks cost" would pick the wrong source on a shop with two.
	 * A zero is cost — some things really are free to source, and a shop that
	 * stored a zero meant it.
	 */
	private function cost_is_recorded(): bool {
		global $wpdb;

		$key   = $this->cost_meta_key();
		$cache = 'dfxcaaw_cost_seen_' . md5( $key );
		$seen  = get_transient( $cache );

		if ( false !== $seen ) {
			return '1' === $seen;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' LIMIT 1",
				$key
			)
		);

		set_transient( $cache, null === $found ? '0' : '1', HOUR_IN_SECONDS );

		return null !== $found;
	}

	/**
	 * What one order line cost, as unit cost times quantity.
	 *
	 * @param int $order_id     The order.
	 * @param int $line_item_id The line within it.
	 */
	public function get_line_cost( int $order_id, int $line_item_id ): ?Money {
		$unit_cost = $this->unit_cost(
			(int) wc_get_order_item_meta( $line_item_id, '_variation_id' ),
			(int) wc_get_order_item_meta( $line_item_id, '_product_id' )
		);

		if ( null === $unit_cost ) {
			return null;
		}

		return $unit_cost->times( max( 0, (int) wc_get_order_item_meta( $line_item_id, '_qty' ) ) );
	}

	/**
	 * The unit cost of whichever of these products has one recorded.
	 *
	 * @param int $variation_id The variation ordered, or 0.
	 * @param int $product_id   The parent product.
	 */
	private function unit_cost( int $variation_id, int $product_id ): ?Money {
		foreach ( array( $variation_id, $product_id ) as $candidate ) {
			if ( $candidate < 1 ) {
				continue;
			}

			$stored = get_post_meta( $candidate, $this->cost_meta_key(), true );

			if ( is_numeric( $stored ) ) {
				return Money::from_decimal( (float) $stored, $this->currency, $this->decimals );
			}
		}

		return null;
	}

	/**
	 * A product's current cost is not the cost the order was placed at.
	 */
	public function records_cost_at_sale(): bool {
		return false;
	}

	/**
	 * Warm the caches every product-meta source reads through.
	 *
	 * Two bulk reads in place of a handful per order: every line's meta, and
	 * the cost meta of every product those lines name.
	 *
	 * `order_item` is a meta type WordPress knows about, because WooCommerce
	 * registers the table — its own item data store reads `_product_id` and the
	 * rest through `get_metadata( 'order_item', ... )`, so filling that cache is
	 * filling the cache WooCommerce is about to read. Nothing here reads meta a
	 * different way from the way it is read normally; it is read earlier and all
	 * at once.
	 *
	 * @param list<int> $order_ids     The orders about to be asked about.
	 * @param list<int> $line_item_ids Every line of those orders.
	 */
	public function prime( array $order_ids, array $line_item_ids ): void {
		if ( array() === $line_item_ids ) {
			return;
		}

		update_meta_cache( 'order_item', $line_item_ids );

		$products = array();

		foreach ( $line_item_ids as $line_item_id ) {
			foreach ( array( '_variation_id', '_product_id' ) as $key ) {
				$id = (int) wc_get_order_item_meta( $line_item_id, $key );

				if ( $id > 0 ) {
					$products[ $id ] = $id;
				}
			}
		}

		if ( array() !== $products ) {
			update_meta_cache( 'post', array_values( $products ) );
		}
	}
}
