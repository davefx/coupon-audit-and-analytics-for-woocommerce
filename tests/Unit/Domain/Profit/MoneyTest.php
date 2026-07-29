<?php
/**
 * Money unit tests.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Tests\Unit\Domain\Profit;

use DFX\CouponAAW\Domain\Profit\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Amounts are held as integers in the currency's minor unit (§8.5).
 *
 * Floats are never used for money, and this class exists to make that
 * impossible rather than merely discouraged. The whole product is a claim about
 * a number; a rounding error in the third decimal place would undermine it more
 * thoroughly than any missing feature.
 */
final class MoneyTest extends TestCase {

	/**
	 * The stored amount is exactly what it was given.
	 */
	public function test_it_carries_minor_units(): void {
		$money = new Money( 1250, 'EUR' );

		$this->assertSame( 1250, $money->amount );
		$this->assertSame( 'EUR', $money->currency );
	}

	/**
	 * Currency codes are normalised, so EUR and eur are the same currency.
	 */
	public function test_it_normalises_the_currency_code(): void {
		$this->assertSame( 'EUR', ( new Money( 100, 'eur' ) )->currency );
		$this->assertTrue( ( new Money( 100, 'eur' ) )->equals( new Money( 100, 'EUR' ) ) );
	}

	/**
	 * A currency code has to look like one.
	 */
	public function test_it_rejects_a_currency_that_is_not_a_code(): void {
		$this->expectException( InvalidArgumentException::class );

		new Money( 100, 'euros' );
	}

	/**
	 * Costs cannot be negative, but margins certainly can.
	 */
	public function test_it_allows_a_negative_amount(): void {
		$this->assertTrue( ( new Money( -500, 'EUR' ) )->is_negative() );
		$this->assertFalse( ( new Money( 500, 'EUR' ) )->is_negative() );
	}

	/**
	 * Zero is a normal amount, not an absent one.
	 */
	public function test_zero_is_an_amount(): void {
		$zero = Money::zero( 'EUR' );

		$this->assertSame( 0, $zero->amount );
		$this->assertTrue( $zero->is_zero() );
	}

	/**
	 * WooCommerce hands out decimal strings and floats. Converting is where
	 * precision is lost, so it happens exactly once, here, and rounds rather
	 * than truncating: (int) ( 0.29 * 100 ) is 28 on most builds.
	 *
	 * @dataProvider provide_decimals
	 *
	 * @param float|string $amount   What WooCommerce gave us.
	 * @param int          $decimals Places in the currency's minor unit.
	 * @param int          $expected The exact minor-unit value.
	 */
	public function test_it_converts_decimals_without_losing_a_unit( float|string $amount, int $decimals, int $expected ): void {
		$this->assertSame( $expected, Money::from_decimal( $amount, 'EUR', $decimals )->amount );
	}

	/**
	 * Decimal amounts that are easy to get wrong.
	 *
	 * @return array<string, array{float|string, int, int}>
	 */
	public static function provide_decimals(): array {
		return array(
			'plain'                 => array( 12.50, 2, 1250 ),
			'the classic 0.29 case' => array( 0.29, 2, 29 ),
			'one that repeats'      => array( 1.15, 2, 115 ),
			'a string'              => array( '19.99', 2, 1999 ),
			'rounds half up'        => array( 0.005, 2, 1 ),
			'zero decimals'         => array( 1250.0, 0, 1250 ),
			'three decimals'        => array( 1.234, 3, 1234 ),
			'negative'              => array( -3.75, 2, -375 ),
			'empty string is zero'  => array( '', 2, 0 ),
		);
	}

	/**
	 * Addition and subtraction stay in minor units.
	 */
	public function test_it_adds_and_subtracts(): void {
		$ten  = new Money( 1000, 'EUR' );
		$four = new Money( 400, 'EUR' );

		$this->assertSame( 1400, $ten->plus( $four )->amount );
		$this->assertSame( 600, $ten->minus( $four )->amount );
	}

	/**
	 * Multiplying by a quantity is how a unit cost becomes a line cost.
	 */
	public function test_it_multiplies_by_a_quantity(): void {
		$this->assertSame( 750, ( new Money( 250, 'EUR' ) )->times( 3 )->amount );
	}

	/**
	 * Arithmetic never mutates.
	 */
	public function test_arithmetic_returns_a_new_amount(): void {
		$original = new Money( 1000, 'EUR' );
		$original->plus( new Money( 500, 'EUR' ) );

		$this->assertSame( 1000, $original->amount );
	}

	/**
	 * §8.5 is unambiguous: mixed currencies are aggregated separately and never
	 * summed. Silently adding them would produce a total that means nothing and
	 * looks entirely plausible.
	 */
	public function test_it_refuses_to_add_different_currencies(): void {
		$this->expectException( InvalidArgumentException::class );

		( new Money( 1000, 'EUR' ) )->plus( new Money( 1000, 'USD' ) );
	}

	/**
	 * The same refusal applies to subtraction.
	 */
	public function test_it_refuses_to_subtract_different_currencies(): void {
		$this->expectException( InvalidArgumentException::class );

		( new Money( 1000, 'EUR' ) )->minus( new Money( 1000, 'USD' ) );
	}

	/**
	 * Amounts in different currencies are never equal, however the numbers look.
	 */
	public function test_amounts_in_different_currencies_are_not_equal(): void {
		$this->assertFalse( ( new Money( 1000, 'EUR' ) )->equals( new Money( 1000, 'USD' ) ) );
	}

	/**
	 * Comparison is by value within one currency.
	 */
	public function test_it_compares_amounts_in_the_same_currency(): void {
		$this->assertTrue( ( new Money( 1000, 'EUR' ) )->equals( new Money( 1000, 'EUR' ) ) );
		$this->assertFalse( ( new Money( 1000, 'EUR' ) )->equals( new Money( 1001, 'EUR' ) ) );
	}
}
