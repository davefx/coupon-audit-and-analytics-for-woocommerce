<?php
/**
 * What a coupon takes off.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Coupon;

use DFX\CouponAAW\Domain\Profit\Money;
use InvalidArgumentException;

/**
 * A discount is either a share of the basket or a sum of money, and the
 * difference matters for more than display.
 *
 * A fixed discount can exceed what it is applied to — a £10 coupon against a £6
 * product hands money back — and that is a real fault this plugin reports. A
 * percentage cannot, whatever it is set to. Keeping the two apart in the type
 * means the check cannot be written against the wrong one.
 *
 * WooCommerce's discount types are extensible, so the raw type is carried
 * alongside rather than folded into an enum that would misfile anything custom.
 */
final class DiscountAmount {

	/**
	 * Constructor.
	 *
	 * @param string     $type    WooCommerce's discount type slug.
	 * @param Money|null $fixed   The sum taken off, for a fixed discount.
	 * @param float|null $percent The share taken off, for a percentage discount.
	 *
	 * @throws InvalidArgumentException When neither or both are given.
	 */
	private function __construct(
		public readonly string $type,
		public readonly ?Money $fixed,
		public readonly ?float $percent
	) {
		if ( ( null === $fixed ) === ( null === $percent ) ) {
			throw new InvalidArgumentException( 'A discount is either a fixed sum or a percentage, not both and not neither.' );
		}
	}

	/**
	 * A discount expressed as a share of the basket.
	 *
	 * @param float  $percent The share.
	 * @param string $type    WooCommerce's discount type slug.
	 */
	public static function percentage( float $percent, string $type = 'percent' ): self {
		return new self( $type, null, $percent );
	}

	/**
	 * A discount expressed as a sum of money.
	 *
	 * @param Money  $amount The sum.
	 * @param string $type   WooCommerce's discount type slug.
	 */
	public static function fixed( Money $amount, string $type = 'fixed_cart' ): self {
		return new self( $type, $amount, null );
	}

	/**
	 * Whether this is a share of the basket.
	 */
	public function is_percentage(): bool {
		return null !== $this->percent;
	}

	/**
	 * Whether this is a sum of money, and so capable of exceeding what it is
	 * applied to.
	 */
	public function is_fixed(): bool {
		return null !== $this->fixed;
	}
}
