<?php
/**
 * Narrowing an inventory.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Coupon;

/**
 * Which coupons somebody is asking to see.
 *
 * A shop with four hundred coupons cannot be read top to bottom, so the screen
 * is only useful if it can be asked a question. This holds the question. It says
 * nothing about whether a coupon is *wrong* — that is what the findings are for
 * — only about what is being looked at, which is why an unset filter matches
 * everything rather than nothing.
 *
 * Conditions are combined with "and", because two of them narrowing to the union
 * would widen the result as you added them, which is not how anybody expects a
 * filter to behave.
 *
 * Two of the four conditions cannot be answered by the coupon alone. Whether
 * anything was found against it is judged against the whole shop, and its status
 * depends on the time; both are handed in, already decided, by whoever is doing
 * the filtering. This class still decides nothing — it compares.
 */
final class CouponFilter {

	/**
	 * Coupons something was found against.
	 *
	 * The same rule the "needs attention" tile counts by, so the number above the
	 * table and the rows in it cannot disagree.
	 */
	public const FINDING_ATTENTION = 'attention';

	/**
	 * Coupons that can be redeemed and apply to the whole catalogue.
	 */
	public const FINDING_UNRESTRICTED = 'unrestricted';

	/**
	 * Constructor.
	 *
	 * @param string|null             $discount_type WooCommerce's discount type slug, or null for any.
	 * @param bool|null               $has_expiry    Whether the coupon expires, or null for either.
	 * @param string|null             $finding       One of the FINDING_ constants, or null for any coupon.
	 * @param list<CouponStatus>|null $statuses     The statuses wanted, or null for every status.
	 */
	public function __construct(
		public readonly ?string $discount_type = null,
		public readonly ?bool $has_expiry = null,
		public readonly ?string $finding = null,
		public readonly ?array $statuses = null
	) {}

	/**
	 * Whether this asks for anything at all.
	 */
	public function is_empty(): bool {
		return null === $this->discount_type
			&& null === $this->has_expiry
			&& null === $this->finding
			&& null === $this->statuses;
	}

	/**
	 * Whether a coupon is one of the ones being asked for.
	 *
	 * Both questions it can ask are answered by a scalar the projection already
	 * carries, so the screen can narrow twenty-six thousand coupons without
	 * building one of them.
	 *
	 * The status and the findings are passed in rather than worked out, because
	 * neither is a property of the coupon: status needs a clock and orphanhood
	 * needs every other coupon in the shop. Both are already in hand wherever
	 * this is called, and neither has a default — a filter asked about a finding
	 * without being told the findings would answer "no" for every coupon and
	 * empty the table, which is the one failure this class exists to avoid.
	 *
	 * @param Judgeable          $coupon  The coupon to judge.
	 * @param CouponStatus       $status  Its resolved status.
	 * @param list<OrphanReason> $reasons What was found against it, possibly nothing.
	 */
	public function matches( Judgeable $coupon, CouponStatus $status, array $reasons ): bool {
		if ( null !== $this->discount_type && $coupon->discount_type() !== $this->discount_type ) {
			return false;
		}

		if ( null !== $this->has_expiry && ( null !== $coupon->expires_at() ) !== $this->has_expiry ) {
			return false;
		}

		if ( null !== $this->statuses && ! in_array( $status, $this->statuses, true ) ) {
			return false;
		}

		return $this->finds( $status, $coupon, $reasons );
	}

	/**
	 * Whether the coupon shows whichever finding was asked for.
	 *
	 * A finding this does not recognise matches everything rather than nothing.
	 * It arrives from a URL, and a hand-edited one must not empty the table: a
	 * shop reading an empty coupon audit has every reason to think something has
	 * gone badly wrong.
	 *
	 * @param CouponStatus       $status  The coupon's resolved status.
	 * @param Judgeable          $coupon  The coupon.
	 * @param list<OrphanReason> $reasons What was found against it.
	 */
	private function finds( CouponStatus $status, Judgeable $coupon, array $reasons ): bool {
		return match ( $this->finding ) {
			self::FINDING_ATTENTION    => array() !== $reasons,
			self::FINDING_UNRESTRICTED => $status->is_usable() && $coupon->is_universal(),
			default                    => true,
		};
	}
}
