<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Tests\Fixtures;

use Ttpryg\OrderEngine\Services\OrderCalculationService;
use Ttpryg\OrderEngine\ValueObjects\Money;

/**
 * Kalkulator contoh untuk menguji injection: menambahkan iuran Rp 0,50 per item
 * dan biaya layanan Rp 10,00 per order di atas formula dasar.
 */
final class FeeApplyingCalculationService extends OrderCalculationService
{
    private const ITEM_FEE_MINOR = 50;

    private const ORDER_FEE_MINOR = 1000;

    public function calculateItemSubtotal(
        int $quantity,
        Money|int|float|string $unitPrice,
        Money|int|float|string $discountTotal = 0,
        Money|int|float|string $taxTotal = 0
    ): Money {
        $subtotal = parent::calculateItemSubtotal($quantity, $unitPrice, $discountTotal, $taxTotal);

        return $subtotal->add(Money::ofMinor(self::ITEM_FEE_MINOR, $subtotal->currency));
    }

    public function calculateGrandTotal(
        Money|int|float|string $subtotal,
        Money|int|float|string $discountTotal = 0,
        Money|int|float|string $taxTotal = 0,
        Money|int|float|string $shippingTotal = 0
    ): Money {
        $grandTotal = parent::calculateGrandTotal($subtotal, $discountTotal, $taxTotal, $shippingTotal);

        return $grandTotal->add(Money::ofMinor(self::ORDER_FEE_MINOR, $grandTotal->currency));
    }
}
