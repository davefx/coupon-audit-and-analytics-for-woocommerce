<?php
/**
 * Checking a coupon's own terms.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Coupon;

use DFX\CouponAAW\Domain\Profit\Money;

/**
 * Finds coupons whose terms give away more than the shop can have meant.
 *
 * Only fixed-sum discounts can be wrong this way. A percentage takes a share of
 * whatever is there and cannot exceed it; a fixed sum has no such limit, and
 * WooCommerce will happily apply a £10 coupon to a £6 basket.
 *
 * The cheapest price the coupon can reach is supplied rather than looked up:
 * working it out means the catalogue, and the catalogue is not something the
 * domain is allowed to know about (§5).
 */
final class ConfigurationAuditor {

	/**
	 * Every fault in this coupon's terms, in a stable order.
	 *
	 * @param CouponSnapshot $coupon   The coupon to check.
	 * @param Money|null     $cheapest The lowest price the coupon can be applied
	 *                                 to, or null where that is not known — an
	 *                                 unrestricted coupon, or a shop whose
	 *                                 catalogue could not be read.
	 *
	 * @return list<ConfigurationIssue>
	 */
	public function issues( CouponSnapshot $coupon, ?Money $cheapest = null ): array {
		$discount = $coupon->terms->amount->fixed;

		if ( null === $discount || $discount->is_zero() ) {
			return array();
		}

		$issues  = array();
		$minimum = $coupon->terms->minimum_spend;

		if ( null !== $minimum && ! $minimum->is_zero() && $this->exceeds( $discount, $minimum ) ) {
			$issues[] = ConfigurationIssue::DISCOUNT_EXCEEDS_MINIMUM_SPEND;
		}

		if ( null !== $cheapest && $this->exceeds( $discount, $cheapest ) ) {
			$issues[] = ConfigurationIssue::DISCOUNT_EXCEEDS_PRODUCT_PRICE;
		}

		if ( array() === $issues && null === $minimum && $coupon->scope->is_universal() ) {
			$issues[] = ConfigurationIssue::UNBOUNDED_FIXED_DISCOUNT;
		}

		return $issues;
	}

	/**
	 * Whether the discount is worth more than the amount it meets.
	 *
	 * Currencies that differ are not compared at all. §8.5 keeps them apart, and
	 * a shop whose coupon and catalogue are denominated differently is telling
	 * us something we cannot resolve rather than something we should guess at.
	 *
	 * @param Money $discount The sum taken off.
	 * @param Money $against  The sum it is measured against.
	 */
	private function exceeds( Money $discount, Money $against ): bool {
		if ( $discount->currency !== $against->currency ) {
			return false;
		}

		return $discount->amount > $against->amount;
	}
}
