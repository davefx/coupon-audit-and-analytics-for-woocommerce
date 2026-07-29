<?php
/**
 * Coupon snapshot.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Coupon;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One coupon as the repository found it: immutable, self-validating, and
 * ignorant of where it came from.
 *
 * It answers no derived questions. Status, orphanhood and overlap are computed
 * by classes that can be given a clock and a threshold; a snapshot that
 * computed its own status would need a clock of its own, and every repository
 * would have to thread one through.
 */
final class CouponSnapshot {

	/**
	 * Constructor.
	 *
	 * @param CouponId               $id           Post ID of the coupon.
	 * @param string                 $code         The coupon code as entered at checkout.
	 * @param bool                   $is_published Whether the coupon post is published.
	 * @param DateTimeImmutable      $created_at   When the coupon was created.
	 * @param DateTimeImmutable|null $starts_at    Start of the validity window, if any.
	 * @param DateTimeImmutable|null $expires_at   End of the validity window, if any.
	 * @param int|null               $usage_limit  Maximum redemptions, or null for unlimited.
	 * @param int                    $usage_count  Redemptions so far.
	 * @param DateTimeImmutable|null $last_used_at Last redemption, or null if never used.
	 * @param CouponScope            $scope           The products the coupon affects.
	 * @param bool                   $is_auto_applied Whether it applies without the customer entering it.
	 *
	 * @throws InvalidArgumentException When the data could not describe a real coupon.
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
		public readonly CouponScope $scope,
		public readonly bool $is_auto_applied
	) {
		if ( '' === trim( $code ) ) {
			throw new InvalidArgumentException( 'A coupon must have a code.' );
		}

		if ( $usage_count < 0 ) {
			throw new InvalidArgumentException( 'A coupon usage count cannot be negative.' );
		}

		if ( null !== $usage_limit && $usage_limit < 1 ) {
			throw new InvalidArgumentException(
				'A coupon usage limit must be null for unlimited, or at least 1.'
			);
		}
	}

	/**
	 * Whether every permitted redemption has been spent.
	 *
	 * Intrinsic to the data and independent of the clock, unlike the rest of
	 * status resolution.
	 */
	public function has_reached_usage_limit(): bool {
		return null !== $this->usage_limit && $this->usage_count >= $this->usage_limit;
	}
}
