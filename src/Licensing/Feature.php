<?php
/**
 * The things this plugin can do.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Licensing;

/**
 * Every capability that is either free or paid for (§11).
 *
 * Listing the paid ones here in the free release is deliberate. There is one
 * distributable package; paid features are present in the code and closed behind
 * the gate, so shipping them later changes a licence answer rather than a build.
 */
enum Feature: string {

	/**
	 * The coupon inventory, and every finding it produces. Free.
	 */
	case INVENTORY = 'inventory';

	/**
	 * Gross margin over the trailing thirty days. Free.
	 */
	case GROSS_MARGIN_30D = 'gross_margin_30d';

	/**
	 * Warnings on the coupon edit screen. Free.
	 */
	case PRE_PUBLISH_BASIC = 'pre_publish_basic';

	/**
	 * Margin over any period, and comparisons between periods.
	 */
	case FULL_HISTORY = 'full_history';

	/**
	 * Margin after costs beyond goods.
	 */
	case NET_MARGIN = 'net_margin';

	/**
	 * Which customers a coupon brought.
	 */
	case CUSTOMER_SEGMENTS = 'customer_segments';

	/**
	 * Being told when something changes rather than going to look.
	 */
	case ALERTS = 'alerts';

	/**
	 * Taking the figures elsewhere.
	 */
	case EXPORT = 'export';

	/**
	 * Whether this is part of the free tier.
	 *
	 * The list lives on the enum rather than in the gate so that both the free
	 * gate and the licensed one answer from the same place, and neither can
	 * drift into disagreeing about what was ever free.
	 */
	public function is_free(): bool {
		return match ( $this ) {
			self::INVENTORY, self::GROSS_MARGIN_30D, self::PRE_PUBLISH_BASIC => true,
			default => false,
		};
	}
}
