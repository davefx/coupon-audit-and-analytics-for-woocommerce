<?php
/**
 * An amount of money.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Profit;

use InvalidArgumentException;

/**
 * An integer number of minor units, and the currency they are in (§8.5).
 *
 * Floats are never used for money anywhere in this codebase, and this class
 * exists to make that impossible rather than merely discouraged. The whole
 * product is a claim about a number; a rounding error in the third decimal place
 * would undermine it more thoroughly than any missing feature could.
 *
 * Mixed currencies are aggregated separately and never summed. Arithmetic
 * between two currencies throws rather than returning something plausible.
 */
final class Money {

	/**
	 * ISO 4217 code, upper-cased.
	 *
	 * @var string
	 */
	public readonly string $currency;

	/**
	 * Constructor.
	 *
	 * @param int    $amount   The amount, in the currency's minor unit.
	 * @param string $currency ISO 4217 code.
	 *
	 * @throws InvalidArgumentException When the currency is not a three-letter code.
	 */
	public function __construct(
		public readonly int $amount,
		string $currency
	) {
		$normalised = strtoupper( trim( $currency ) );

		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $normalised ) ) {
			throw new InvalidArgumentException( 'A currency must be a three-letter ISO 4217 code.' );
		}

		$this->currency = $normalised;
	}

	/**
	 * Nothing, in a given currency.
	 *
	 * @param string $currency ISO 4217 code.
	 */
	public static function zero( string $currency ): self {
		return new self( 0, $currency );
	}

	/**
	 * Convert a decimal amount into minor units.
	 *
	 * This is the only place a float is allowed near money, because it is the
	 * boundary WooCommerce hands its values across. It rounds rather than
	 * truncating: `(int) ( 0.29 * 100 )` is 28 on most builds, and a plugin that
	 * loses a cent per line loses all its credibility with it.
	 *
	 * @param float|string $amount   The decimal amount.
	 * @param string       $currency ISO 4217 code.
	 * @param int          $decimals Places in the currency's minor unit.
	 */
	public static function from_decimal( float|string $amount, string $currency, int $decimals ): self {
		return new self(
			(int) round( (float) $amount * ( 10 ** $decimals ) ),
			$currency
		);
	}

	/**
	 * This amount plus another.
	 *
	 * @param self $other The amount to add.
	 *
	 * @throws InvalidArgumentException When the currencies differ.
	 */
	public function plus( self $other ): self {
		$this->assert_same_currency( $other );

		return new self( $this->amount + $other->amount, $this->currency );
	}

	/**
	 * This amount less another.
	 *
	 * @param self $other The amount to subtract.
	 *
	 * @throws InvalidArgumentException When the currencies differ.
	 */
	public function minus( self $other ): self {
		$this->assert_same_currency( $other );

		return new self( $this->amount - $other->amount, $this->currency );
	}

	/**
	 * This amount repeated, which is how a unit cost becomes a line cost.
	 *
	 * @param int $quantity How many.
	 */
	public function times( int $quantity ): self {
		return new self( $this->amount * $quantity, $this->currency );
	}

	/**
	 * Whether this is less than nothing. Costs cannot be; margins can.
	 */
	public function is_negative(): bool {
		return $this->amount < 0;
	}

	/**
	 * Whether this is exactly nothing.
	 */
	public function is_zero(): bool {
		return 0 === $this->amount;
	}

	/**
	 * Whether two amounts are the same amount of the same currency.
	 *
	 * @param self $other The amount to compare against.
	 */
	public function equals( self $other ): bool {
		return $this->amount === $other->amount && $this->currency === $other->currency;
	}

	/**
	 * Refuse to combine two currencies.
	 *
	 * @param self $other The other amount.
	 *
	 * @throws InvalidArgumentException When the currencies differ.
	 */
	private function assert_same_currency( self $other ): void {
		if ( $this->currency !== $other->currency ) {
			throw new InvalidArgumentException(
				'Cannot combine two different currencies; they are aggregated separately.'
			);
		}
	}
}
