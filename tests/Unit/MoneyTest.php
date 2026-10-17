<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ttpryg\OrderEngine\ValueObjects\Money;

class MoneyTest extends TestCase
{
    public function test_from_decimal_parsing(): void
    {
        $this->assertSame(1500000000, Money::fromDecimal('15000000.00')->minorUnits());
        $this->assertSame(1500000000, Money::fromDecimal(15000000)->minorUnits());
        $this->assertSame(1500000000, Money::fromDecimal(15000000.0)->minorUnits());
        $this->assertSame(123456, Money::fromDecimal('1234.56')->minorUnits());
        $this->assertSame(50, Money::fromDecimal('.50')->minorUnits());
    }

    public function test_fractional_float_input_is_rounded_half_up(): void
    {
        $this->assertSame(10, Money::fromDecimal(0.1)->minorUnits());
        $this->assertSame(30, Money::fromDecimal('0.3')->minorUnits());
        $this->assertSame(1, Money::fromDecimal('0.005')->minorUnits());
        $this->assertSame(0, Money::fromDecimal('0.004')->minorUnits());
        $this->assertSame(-1, Money::fromDecimal('-0.005')->minorUnits());
    }

    public function test_fractional_addition_is_exact(): void
    {
        $total = Money::fromDecimal(0.1)->add(Money::fromDecimal(0.2));

        $this->assertSame('0.30', $total->toDecimal());
        $this->assertSame(30, $total->minorUnits());
    }

    public function test_add_subtract_and_multiply(): void
    {
        $price = Money::fromDecimal(12000000);

        $this->assertSame('24000000.00', $price->multiply(2)->toDecimal());
        $this->assertSame('11975000.00', $price->subtract(Money::fromDecimal(25000))->toDecimal());
        $this->assertSame('12025000.00', $price->add(Money::fromDecimal(25000))->toDecimal());
    }

    public function test_subtraction_can_produce_negative_result(): void
    {
        $result = Money::fromDecimal(100)->subtract(Money::fromDecimal(250));

        $this->assertTrue($result->isNegative());
        $this->assertSame('-150.00', $result->toDecimal());
    }

    public function test_min_max_compare_and_equals(): void
    {
        $small = Money::fromDecimal(100);
        $large = Money::fromDecimal(200);

        $this->assertTrue($small->min($large)->equals($small));
        $this->assertTrue($small->max($large)->equals($large));
        $this->assertSame(-1, $small->compareTo($large));
        $this->assertSame(0, $small->compareTo(Money::fromDecimal(100)));
        $this->assertSame(1, $large->compareTo($small));
        $this->assertFalse($small->equals(Money::fromDecimal(100, 'USD')));
    }

    public function test_currency_mismatch_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal(1000, 'IDR')->add(Money::fromDecimal(1000, 'USD'));
    }

    public function test_invalid_currency_code_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::ofMinor(100, 'Rupiah');
    }

    public function test_invalid_decimal_string_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('seratus ribu');
    }

    public function test_from_rejects_mismatched_money_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::from(Money::fromDecimal(100, 'USD'), 'IDR');
    }

    public function test_from_database_value(): void
    {
        $this->assertSame('12025000.00', Money::fromDatabaseValue('12025000.00')->toDecimal());
        $this->assertSame('12025000.00', Money::fromDatabaseValue(12025000.0)->toDecimal());
        $this->assertSame('12025000.00', Money::fromDatabaseValue(12025000)->toDecimal());

        $this->expectException(InvalidArgumentException::class);
        Money::fromDatabaseValue(null);
    }

    public function test_with_currency_relabels_without_changing_amount(): void
    {
        $money = Money::fromDecimal(50000);

        $relabeled = $money->withCurrency('USD');

        $this->assertSame('USD', $relabeled->currency);
        $this->assertSame(5000000, $relabeled->minorUnits());
        $this->assertSame('50000.00', $relabeled->toDecimal());
    }

    public function test_resolve_currency_prefers_money_input(): void
    {
        $this->assertSame('USD', Money::resolveCurrency(100, Money::fromDecimal(1, 'USD'), 500));
        $this->assertSame('IDR', Money::resolveCurrency(100, 200));
    }

    public function test_to_float_is_only_for_presentation(): void
    {
        $this->assertSame(12025000.0, Money::fromDecimal('12025000.00')->toFloat());
    }
}
