<?php
/**
 * Reading the filter out of the request.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Admin;

use DFX\CouponAAW\Domain\Coupon\CouponFilter;
use DFX\CouponAAW\Domain\Coupon\CouponStatus;

/**
 * Turns what the URL says into a filter the domain understands.
 *
 * A screen reads input and formats output; it decides nothing (§5). So this
 * translates and validates, and `CouponFilter` does the judging.
 *
 * Anything it does not recognise is dropped rather than obeyed. A hand-edited
 * URL naming a discount type nobody offers would otherwise empty the table, and
 * a shop reading an empty coupon audit has every reason to think something has
 * gone badly wrong.
 *
 * This is also where the screen's *default* lives: with nothing asked for, it
 * asks for the coupons still in force. A shop with twenty-six thousand coupons
 * mostly has twenty-six thousand dead ones — a code minted per customer, spent
 * once, expired years ago — and a list of those is not an audit of anything.
 *
 * The default belongs here and not in `CouponFilter`, which still matches
 * everything when nobody has set it. That distinction is load-bearing: the
 * pre-publish check and anything else judging the whole shop must keep seeing
 * the whole shop. It is the screen that has an opinion about where to start,
 * and the screen says so above its table and offers the way back.
 */
final class InventoryFilterRequest {

	/**
	 * The query argument naming a discount type.
	 */
	public const TYPE_ARG = 'dfxcaaw_type';

	/**
	 * The query argument naming an expiry condition.
	 */
	public const EXPIRY_ARG = 'dfxcaaw_expiry';

	/**
	 * Coupons that expire.
	 */
	public const EXPIRY_WITH = 'with';

	/**
	 * Coupons that never do.
	 */
	public const EXPIRY_WITHOUT = 'without';

	/**
	 * The query argument naming a status, or asking for all of them.
	 */
	public const STATUS_ARG = 'dfxcaaw_status';

	/**
	 * Every status, including the ones the screen leaves out by default.
	 */
	public const STATUS_ALL = 'all';

	/**
	 * The query argument naming a finding to narrow to.
	 */
	public const FINDING_ARG = 'dfxcaaw_finding';

	/**
	 * The filter the current request asks for.
	 *
	 * @param array<string, mixed> $query The request, normally `$_GET`.
	 */
	public static function from( array $query ): CouponFilter {
		return new CouponFilter(
			self::discount_type( $query ),
			self::has_expiry( $query ),
			self::finding( $query ),
			self::statuses( $query )
		);
	}

	/**
	 * The statuses the request asks for, or the screen's default.
	 *
	 * `all` is the only way to widen it, and it is offered on the screen rather
	 * than being something a reader has to know to type. Anything unrecognised
	 * falls back to the default: narrowing to a status nobody has is an empty
	 * table, which reads as a broken screen.
	 *
	 * @param array<string, mixed> $query The request.
	 *
	 * @return list<CouponStatus>|null
	 */
	private static function statuses( array $query ): ?array {
		$asked = isset( $query[ self::STATUS_ARG ] ) ? sanitize_key( wp_unslash( $query[ self::STATUS_ARG ] ) ) : '';

		if ( self::STATUS_ALL === $asked ) {
			return null;
		}

		$status = CouponStatus::tryFrom( $asked );

		return array( $status ?? CouponStatus::ACTIVE );
	}

	/**
	 * The finding the request asks to see, if it is one this screen offers.
	 *
	 * @param array<string, mixed> $query The request.
	 */
	private static function finding( array $query ): ?string {
		$asked = isset( $query[ self::FINDING_ARG ] ) ? sanitize_key( wp_unslash( $query[ self::FINDING_ARG ] ) ) : '';

		return in_array( $asked, array( CouponFilter::FINDING_ATTENTION, CouponFilter::FINDING_UNRESTRICTED ), true )
			? $asked
			: null;
	}

	/**
	 * The discount types this store actually offers.
	 *
	 * Asked of WooCommerce rather than listed here: the types are filterable, so
	 * a store may have more than core ships with, and a hard-coded list would
	 * both miss those and keep offering any that core drops.
	 *
	 * @return array<string, string> Slug to label.
	 */
	public static function discount_types(): array {
		$types = function_exists( 'wc_get_coupon_types' ) ? wc_get_coupon_types() : array();

		return is_array( $types ) ? $types : array();
	}

	/**
	 * The discount type asked for, if it is one this store offers.
	 *
	 * @param array<string, mixed> $query The request.
	 */
	private static function discount_type( array $query ): ?string {
		$type = isset( $query[ self::TYPE_ARG ] ) ? sanitize_key( wp_unslash( $query[ self::TYPE_ARG ] ) ) : '';

		return array_key_exists( $type, self::discount_types() ) ? $type : null;
	}

	/**
	 * Whether the request asks for coupons that expire, that do not, or either.
	 *
	 * @param array<string, mixed> $query The request.
	 */
	private static function has_expiry( array $query ): ?bool {
		$expiry = isset( $query[ self::EXPIRY_ARG ] ) ? sanitize_key( wp_unslash( $query[ self::EXPIRY_ARG ] ) ) : '';

		return match ( $expiry ) {
			self::EXPIRY_WITH => true,
			self::EXPIRY_WITHOUT => false,
			default => null,
		};
	}
}
