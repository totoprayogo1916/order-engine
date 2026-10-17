<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ttpryg\OrderEngine\Entities\OrderItem;
use Ttpryg\OrderEngine\Services\OrderCalculationService;
use Ttpryg\OrderEngine\ValueObjects\Money;

class OrderCalculationServiceTest extends TestCase
{
    private OrderCalculationService $calculator;

    protected function setUp(): void
    {
        $this->calculator = new OrderCalculationService;
    }

    public function test_calculate_item_subtotal(): void
    {
        $subtotal = $this->calculator->calculateItemSubtotal(
            quantity: 2,
            unitPrice: Money::fromDecimal(50000),
            discountTotal: Money::fromDecimal(10000),
            taxTotal: Money::fromDecimal(5000)
        );

        $this->assertSame('95000.00', $subtotal->toDecimal());
        $this->assertSame(9500000, $subtotal->minorUnits());
    }

    public function test_calculate_item_subtotal_clamps_negative_result_to_zero(): void
    {
        $subtotal = $this->calculator->calculateItemSubtotal(
            quantity: 1,
            unitPrice: 1000,
            discountTotal: 5000,
            taxTotal: 0
        );

        $this->assertSame('0.00', $subtotal->toDecimal());
        $this->assertTrue($subtotal->isZero());
    }

    public function test_calculate_subtotal_and_grand_total(): void
    {
        $item1 = new OrderItem(
            id: 'item-1',
            orderId: 'ord-1',
            productId: 'prod-1',
            name: 'Product A',
            quantity: 2,
            unitPrice: Money::fromDecimal(100000)
        );

        $item2 = new OrderItem(
            id: 'item-2',
            orderId: 'ord-1',
            productId: 'prod-2',
            name: 'Product B',
            quantity: 1,
            unitPrice: Money::fromDecimal(50000)
        );

        $subtotal = $this->calculator->calculateSubtotal([$item1, $item2]);
        $this->assertSame('250000.00', $subtotal->toDecimal());

        $grandTotal = $this->calculator->calculateGrandTotal(
            subtotal: $subtotal,
            discountTotal: Money::fromDecimal(20000),
            taxTotal: Money::fromDecimal(10000),
            shippingTotal: Money::fromDecimal(15000)
        );

        $this->assertSame('255000.00', $grandTotal->toDecimal());
    }

    public function test_fractional_float_input_produces_exact_result(): void
    {
        $subtotal = $this->calculator->calculateItemSubtotal(
            quantity: 3,
            unitPrice: 0.1
        );

        $this->assertSame('0.30', $subtotal->toDecimal());
    }

    public function test_mismatched_currencies_throw_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculateGrandTotal(
            subtotal: Money::fromDecimal(100000, 'USD'),
            shippingTotal: Money::fromDecimal(5000, 'IDR')
        );
    }
}
