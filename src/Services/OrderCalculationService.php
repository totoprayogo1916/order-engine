<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Services;

use Ttpryg\OrderEngine\Entities\OrderItem;
use Ttpryg\OrderEngine\ValueObjects\Money;

/**
 * Single source of truth untuk kalkulasi diskon, pajak, subtotal, dan grand total.
 *
 * Seluruh operasi dilakukan dalam representasi Money (integer minor units),
 * sehingga tidak ada perbandingan atau penjumlahan float di jalur finansial.
 */
class OrderCalculationService
{
    public function calculateItemSubtotal(
        int $quantity,
        Money|int|float|string $unitPrice,
        Money|int|float|string $discountTotal = 0,
        Money|int|float|string $taxTotal = 0
    ): Money {
        $currency = Money::resolveCurrency($unitPrice, $discountTotal, $taxTotal);
        $unitPrice = Money::from($unitPrice, $currency);
        $discountTotal = Money::from($discountTotal, $currency);
        $taxTotal = Money::from($taxTotal, $currency);

        return $unitPrice
            ->multiply($quantity)
            ->subtract($discountTotal)
            ->add($taxTotal)
            ->max(Money::zero($currency));
    }

    /**
     * @param  OrderItem[]  $items
     */
    public function calculateSubtotal(array $items, string $currency = 'IDR'): Money
    {
        $subtotal = Money::zero($currency);
        foreach ($items as $item) {
            $subtotal = $subtotal->add($item->calculateSubtotal());
        }

        return $subtotal;
    }

    public function calculateGrandTotal(
        Money|int|float|string $subtotal,
        Money|int|float|string $discountTotal = 0,
        Money|int|float|string $taxTotal = 0,
        Money|int|float|string $shippingTotal = 0
    ): Money {
        $currency = Money::resolveCurrency($subtotal, $discountTotal, $taxTotal, $shippingTotal);
        $subtotal = Money::from($subtotal, $currency);
        $discountTotal = Money::from($discountTotal, $currency);
        $taxTotal = Money::from($taxTotal, $currency);
        $shippingTotal = Money::from($shippingTotal, $currency);

        return $subtotal
            ->subtract($discountTotal)
            ->add($taxTotal)
            ->add($shippingTotal)
            ->max(Money::zero($currency));
    }
}
