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
	 * What one order line cost, as unit cost times quantity.
	 *
	 * @param int $order_id     The order.
	 * @param int $line_item_id The line within it.
	 */
	public function get_line_cost( int $order_id, int $line_item_id ): ?Money {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Abstract_Order ) {
			return null;
		}

		$item = $order->get_item( $line_item_id );

		if ( ! $item instanceof WC_Order_Item_Product ) {
			return null;
		}

		$unit_cost = $this->unit_cost( $item->get_variation_id(), $item->get_product_id() );

		if ( null === $unit_cost ) {
			return null;
		}

		return $unit_cost->times( max( 0, $item->get_quantity() ) );
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
}
