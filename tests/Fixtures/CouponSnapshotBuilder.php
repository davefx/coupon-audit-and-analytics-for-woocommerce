<?php
/**
 * Builder for coupon snapshots.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Fixtures;

use DateTimeImmutable;
use DateTimeZone;
use DFX\CouponAAW\Domain\Coupon\CouponId;
use DFX\CouponAAW\Domain\Coupon\CouponScope;
use DFX\CouponAAW\Domain\Coupon\CouponSnapshot;

/**
 * Keeps the noise out of the tests.
 *
 * A snapshot has ten fields and most tests care about one of them; naming that
 * one and defaulting the rest is what makes the intent of a test readable at a
 * glance. Defaults describe a plain published coupon with no restrictions.
 */
final class CouponSnapshotBuilder {

	/**
	 * Post ID.
	 *
	 * @var int
	 */
	private int $id = 1;

	/**
	 * Coupon code.
	 *
	 * @var string
	 */
	private string $code = 'TEST';

	/**
	 * Whether the coupon post is published.
	 *
	 * @var bool
	 */
	private bool $is_published = true;

	/**
	 * Creation date.
	 *
	 * @var string
	 */
	private string $created_at = '2026-01-01';

	/**
	 * Start of the validity window, if any.
	 *
	 * @var string|null
	 */
	private ?string $starts_at = null;

	/**
	 * End of the validity window, if any.
	 *
	 * @var string|null
	 */
	private ?string $expires_at = null;

	/**
	 * Maximum number of uses, if any.
	 *
	 * @var int|null
	 */
	private ?int $usage_limit = null;

	/**
	 * Times the coupon has been used.
	 *
	 * @var int
	 */
	private int $usage_count = 0;

	/**
	 * When the coupon was last used, if ever.
	 *
	 * @var string|null
	 */
	private ?string $last_used_at = null;

	/**
	 * The coupon's scope.
	 *
	 * @var CouponScope|null
	 */
	private ?CouponScope $scope = null;

	/**
	 * Start building.
	 */
	public static function make(): self {
		return new self();
	}

	/**
	 * Set the post ID.
	 *
	 * @param int $id Post ID.
	 */
	public function with_id( int $id ): self {
		$this->id = $id;

		return $this;
	}

	/**
	 * Set the coupon code.
	 *
	 * @param string $code Coupon code.
	 */
	public function with_code( string $code ): self {
		$this->code = $code;

		return $this;
	}

	/**
	 * Mark the coupon post as published.
	 */
	public function published(): self {
		$this->is_published = true;

		return $this;
	}

	/**
	 * Mark the coupon post as not published.
	 */
	public function unpublished(): self {
		$this->is_published = false;

		return $this;
	}

	/**
	 * Set the creation date.
	 *
	 * @param string $date Any parseable date.
	 */
	public function created( string $date ): self {
		$this->created_at = $date;

		return $this;
	}

	/**
	 * Set the start of the validity window.
	 *
	 * @param string $date Any parseable date.
	 */
	public function starting( string $date ): self {
		$this->starts_at = $date;

		return $this;
	}

	/**
	 * Set the expiry date.
	 *
	 * @param string $date Any parseable date.
	 */
	public function expiring( string $date ): self {
		$this->expires_at = $date;

		return $this;
	}

	/**
	 * Cap the number of uses.
	 *
	 * @param int $uses Maximum uses.
	 */
	public function limited_to( int $uses ): self {
		$this->usage_limit = $uses;

		return $this;
	}

	/**
	 * Set how many times the coupon has been used.
	 *
	 * @param int $times Usage count.
	 */
	public function used( int $times ): self {
		$this->usage_count = $times;

		return $this;
	}

	/**
	 * Set when the coupon was last used.
	 *
	 * @param string $date Any parseable date.
	 */
	public function last_used( string $date ): self {
		$this->last_used_at = $date;

		return $this;
	}

	/**
	 * Set the coupon's scope.
	 *
	 * @param CouponScope $scope The scope.
	 */
	public function with_scope( CouponScope $scope ): self {
		$this->scope = $scope;

		return $this;
	}

	/**
	 * Produce the snapshot.
	 */
	public function build(): CouponSnapshot {
		return new CouponSnapshot(
			new CouponId( $this->id ),
			$this->code,
			$this->is_published,
			self::date( $this->created_at ),
			self::optional_date( $this->starts_at ),
			self::optional_date( $this->expires_at ),
			$this->usage_limit,
			$this->usage_count,
			self::optional_date( $this->last_used_at ),
			$this->scope ?? CouponScope::universal()
		);
	}

	/**
	 * Parse a date as UTC, so tests never depend on the machine's timezone.
	 *
	 * @param string $date Any parseable date.
	 */
	private static function date( string $date ): DateTimeImmutable {
		return new DateTimeImmutable( $date, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Parse an optional date.
	 *
	 * @param string|null $date Any parseable date, or null.
	 */
	private static function optional_date( ?string $date ): ?DateTimeImmutable {
		return null === $date ? null : self::date( $date );
	}
}
