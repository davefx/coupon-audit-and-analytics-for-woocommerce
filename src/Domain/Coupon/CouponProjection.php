<?php
/**
 * Enough of a coupon to judge it, and no more.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Coupon;

use DateTimeImmutable;

/**
 * A coupon reduced to the scalars the audit needs in bulk.
 *
 * The audit screen used to build a full snapshot of every coupon before it could
 * draw twenty rows: a WC_Coupon, its meta, its scope, its terms. That costs
 * about a second and 27 MB per thousand coupons, which puts the screen out of
 * reach of exactly the shops it would help most — see docs/PERFORMANCE.md for
 * the measurements.
 *
 * Almost nothing the whole inventory is asked for needs that much. Status, the
 * expiry and dormancy findings, the campaign tallies and every summary tile are
 * decided from columns that are already scalars. This carries those, so the
 * expensive snapshot is built only for the rows actually on screen.
 *
 * `is_universal` is the one derived value it carries: the "apply to everything"
 * tile counts it, and it is the only thing the tiles ask of a coupon's scope.
 */
final class CouponProjection implements Judgeable {

	/**
	 * Constructor.
	 *
	 * @param CouponId               $id            The coupon.
	 * @param string                 $code          Its code.
	 * @param bool                   $is_published  Whether the storefront would honour it.
	 * @param DateTimeImmutable      $created_at    When it was created.
	 * @param DateTimeImmutable|null $starts_at     When it starts, if that is in the future.
	 * @param DateTimeImmutable|null $expires_at    When it expires, if it does.
	 * @param int|null               $usage_limit   How often it may be redeemed.
	 * @param int                    $usage_count   How often it has been.
	 * @param DateTimeImmutable|null $last_used_at  When it was last redeemed.
	 * @param bool                   $is_universal  Whether it applies to the whole catalogue.
	 * @param string                 $discount_type Its discount type, for filtering.
	 */
	public function __construct(
		public readonly CouponId $id,
		public readonly string $code,
		public readonly bool $is_published,
		public readonly DateTimeImmutable $created_at,
		public readonly ?DateTimeImmutable $starts_at,
		public readonly ?DateTimeImmutable $expires_at,
		public readonly ?int $usage_limit,
		public readonly int $usage_count,
		public readonly ?DateTimeImmutable $last_used_at,
		public readonly bool $is_universal,
		public readonly string $discount_type
	) {}

	/**
	 * Which coupon this is.
	 */
	public function id(): CouponId {
		return $this->id;
	}

	/**
	 * Its code.
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * When it was created.
	 */
	public function created_at(): DateTimeImmutable {
		return $this->created_at;
	}

	/**
	 * When it was last redeemed, if it ever was.
	 */
	public function last_used_at(): ?DateTimeImmutable {
		return $this->last_used_at;
	}

	/**
	 * Whether it applies to the whole catalogue.
	 */
	public function is_universal(): bool {
		return $this->is_universal;
	}

	/**
	 * Whether the coupon is in a status the storefront would honour.
	 */
	public function is_published(): bool {
		return $this->is_published;
	}

	/**
	 * When it starts, where that is in the future.
	 */
	public function starts_at(): ?DateTimeImmutable {
		return $this->starts_at;
	}

	/**
	 * When it expires, if it does.
	 */
	public function expires_at(): ?DateTimeImmutable {
		return $this->expires_at;
	}

	/**
	 * Whether it has been redeemed as often as it is allowed to be.
	 *
	 * A coupon with no limit can never reach one, however often it is used.
	 */
	public function has_reached_usage_limit(): bool {
		return null !== $this->usage_limit && $this->usage_count >= $this->usage_limit;
	}

	/**
	 * WooCommerce's slug for the kind of discount it gives.
	 */
	public function discount_type(): string {
		return $this->discount_type;
	}
}
