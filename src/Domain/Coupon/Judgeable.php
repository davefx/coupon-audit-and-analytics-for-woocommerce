<?php
/**
 * The part of a coupon that status is decided from.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Coupon;

use DateTimeImmutable;

/**
 * Enough of a coupon to say what state it is in.
 *
 * Implemented by both the full snapshot and the lightweight projection, so that
 * the rules asked about the whole inventory — status, the expiry and dormancy
 * findings, the campaign tallies, the summary tiles — have one implementation
 * each and cannot drift between them. The alternative was to re-express the rule in SQL for the summary
 * counts, and then to own two versions of it — which is the sort of divergence
 * that shows up as the tiles disagreeing with the rows underneath them, with no
 * way to tell which is right.
 */
interface Judgeable {

	/**
	 * Which coupon this is.
	 */
	public function id(): CouponId;

	/**
	 * Its code, which is also what a campaign is derived from.
	 */
	public function code(): string;

	/**
	 * When it was created.
	 */
	public function created_at(): DateTimeImmutable;

	/**
	 * When it was last redeemed, if it ever was.
	 */
	public function last_used_at(): ?DateTimeImmutable;

	/**
	 * Whether it applies to the whole catalogue.
	 */
	public function is_universal(): bool;

	/**
	 * Whether the coupon is in a status the storefront would honour.
	 */
	public function is_published(): bool;

	/**
	 * When it starts, where that is in the future.
	 */
	public function starts_at(): ?DateTimeImmutable;

	/**
	 * When it expires, if it does.
	 */
	public function expires_at(): ?DateTimeImmutable;

	/**
	 * Whether it has been redeemed as often as it is allowed to be.
	 */
	public function has_reached_usage_limit(): bool;

	/**
	 * WooCommerce's slug for the kind of discount it gives.
	 */
	public function discount_type(): string;
}
