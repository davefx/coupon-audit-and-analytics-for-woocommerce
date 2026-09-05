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
final class CouponSnapshot implements Judgeable {

	/**
     * @var CouponId
     * @readonly
     */
    public CouponId $id;
    /**
     * @var string
     * @readonly
     */
    public string $code;
    /**
     * @var bool
     * @readonly
     */
    public bool $is_published;
    /**
     * @var DateTimeImmutable
     * @readonly
     */
    public DateTimeImmutable $created_at;
    /**
     * @var DateTimeImmutable|null
     * @readonly
     */
    public ?DateTimeImmutable $starts_at;
    /**
     * @var DateTimeImmutable|null
     * @readonly
     */
    public ?DateTimeImmutable $expires_at;
    /**
     * @var int|null
     * @readonly
     */
    public ?int $usage_limit;
    /**
     * @var int
     * @readonly
     */
    public int $usage_count;
    /**
     * @var DateTimeImmutable|null
     * @readonly
     */
    public ?DateTimeImmutable $last_used_at;
    /**
     * @var CouponScope
     * @readonly
     */
    public CouponScope $scope;
    /**
     * @var bool
     * @readonly
     */
    public bool $is_auto_applied;
    /**
     * @var CouponTerms
     * @readonly
     */
    public CouponTerms $terms;
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
	 * @param CouponTerms            $terms           What the coupon offers, and on what conditions.
	 *
	 * @throws InvalidArgumentException When the data could not describe a real coupon.
	 */
	public function __construct(
		CouponId $id,
		string $code,
		bool $is_published,
		DateTimeImmutable $created_at,
		?DateTimeImmutable $starts_at,
		?DateTimeImmutable $expires_at,
		?int $usage_limit,
		int $usage_count,
		?DateTimeImmutable $last_used_at,
		CouponScope $scope,
		bool $is_auto_applied,
		CouponTerms $terms
	) {
		$this->id = $id;
        $this->code = $code;
        $this->is_published = $is_published;
        $this->created_at = $created_at;
        $this->starts_at = $starts_at;
        $this->expires_at = $expires_at;
        $this->usage_limit = $usage_limit;
        $this->usage_count = $usage_count;
        $this->last_used_at = $last_used_at;
        $this->scope = $scope;
        $this->is_auto_applied = $is_auto_applied;
        $this->terms = $terms;
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
	 * Which coupon this is.
	 */
	public function id(): CouponId {
		return $this->id;
	}

	/**
	 * Its code.
	 */
	public function discount_type(): string {
		return $this->terms->amount->type;
	}

	/**
	 * The code as entered at the basket.
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
	 *
	 * Delegated to the scope, which is the only thing the bulk rules ask of it.
	 */
	public function is_universal(): bool {
		return $this->scope->is_universal();
	}

	/**
	 * Whether the coupon is in a status the storefront would honour.
	 *
	 * The property and the accessor say the same thing; the accessor exists so
	 * that a projection and a snapshot can be judged by the same rule.
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
	 * Whether every permitted redemption has been spent.
	 *
	 * Intrinsic to the data and independent of the clock, unlike the rest of
	 * status resolution.
	 */
	public function has_reached_usage_limit(): bool {
		return null !== $this->usage_limit && $this->usage_count >= $this->usage_limit;
	}
}
