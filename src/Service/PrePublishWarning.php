<?php
/**
 * One pre-publish warning.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Service;

use DFX\CouponAAW\Domain\Coupon\CouponSnapshot;
use DFX\CouponAAW\Domain\Overlap\OverlapSeverity;

/**
 * Something worth saying about a coupon before it is published.
 *
 * A warning that says only "this overlaps something" gives the user nothing to
 * act on, so a warning carries the coupons it concerns and how serious the
 * collision is, and the admin layer turns that into a sentence.
 */
final class PrePublishWarning {

	/**
	 * Constructor.
	 *
	 * @param PrePublishWarningType $type     What the warning is about.
	 * @param list<CouponSnapshot>  $related  Other coupons the warning concerns, if any.
	 * @param OverlapSeverity|null  $severity How serious the collision is, for overlap warnings.
	 */
	public function __construct(
		public readonly PrePublishWarningType $type,
		public readonly array $related = array(),
		public readonly ?OverlapSeverity $severity = null
	) {}
}
