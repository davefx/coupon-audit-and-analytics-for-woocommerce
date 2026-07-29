<?php
/**
 * A tally of the campaigns in an inventory.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Coupon;

/**
 * How many codes each campaign has, and how many of them have expired.
 *
 * Enough to answer "has every other code in this campaign expired" without
 * walking the inventory again, which is the whole point: that question is asked
 * once per coupon, and answering it by search made a shop's coupon list cost the
 * square of its own size.
 *
 * Counts, not lists, so the answer stays constant-time even for a campaign that
 * holds the entire inventory.
 */
final class CampaignIndex {

	/**
	 * Constructor.
	 *
	 * @param array<string, int> $members How many codes each campaign has.
	 * @param array<string, int> $expired How many of those have expired.
	 * @param array<int, bool>   $ids     Which coupons were counted.
	 */
	public function __construct(
		private readonly array $members,
		private readonly array $expired,
		private readonly array $ids
	) {}

	/**
	 * Whether every other code in this coupon's campaign has expired.
	 *
	 * False where the coupon is the only code in its campaign: one coupon is not
	 * the remnant of anything, and calling it a dead campaign would report a
	 * finding about a campaign that never existed.
	 *
	 * @param CouponSnapshot $coupon   The coupon being judged.
	 * @param string|null    $campaign Its campaign, or null if its code names none.
	 */
	public function every_sibling_expired( CouponSnapshot $coupon, ?string $campaign ): bool {
		if ( null === $campaign ) {
			return false;
		}

		$total   = $this->members[ $campaign ] ?? 0;
		$expired = $this->expired[ $campaign ] ?? 0;

		/*
		 * The coupon is normally one of the counted, and is discounted from its
		 * own campaign's tally. It is only asked about while still usable, so it
		 * is never among the expired. A coupon judged against an inventory it is
		 * not part of — which the single-coupon entry point allows — is compared
		 * against the whole tally instead.
		 */
		if ( isset( $this->ids[ $coupon->id->value ] ) ) {
			--$total;
		}

		return $total > 0 && $expired === $total;
	}
}
