<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Entities;

use DateTimeImmutable;
use Ttpryg\OrderEngine\Services\OrderCalculationService;
use Ttpryg\OrderEngine\ValueObjects\Money;

class OrderItem
{
    public Money $unitPrice;

    public Money $discountTotal;

    public Money $taxTotal;

    public Money $subtotal;

    public function __construct(
        public readonly string $id,
        public readonly string $orderId,
        public readonly string $productId,
        public ?string $variantId = null,
        public ?string $sku = null,
        public string $name = '',
        public int $quantity = 1,
        Money|int|float|string|null $unitPrice = null,
        Money|int|float|string|null $discountTotal = null,
        Money|int|float|string|null $taxTotal = null,
        Money|int|float|string|null $subtotal = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null
    ) {
        $currency = Money::resolveCurrency($unitPrice, $discountTotal, $taxTotal, $subtotal);
        $this->unitPrice = Money::from($unitPrice ?? 0, $currency);
        $this->discountTotal = Money::from($discountTotal ?? 0, $currency);
        $this->taxTotal = Money::from($taxTotal ?? 0, $currency);
        $this->subtotal = Money::from($subtotal ?? 0, $currency);
        $this->createdAt = $createdAt ?? new DateTimeImmutable;
        $this->updatedAt = $updatedAt ?? new DateTimeImmutable;

        if ($this->subtotal->isZero() && $this->quantity > 0 && ! $this->unitPrice->isZero()) {
            $this->subtotal = $this->calculateSubtotal();
        }
    }

    public function calculateSubtotal(): Money
    {
        $this->subtotal = (new OrderCalculationService)->calculateItemSubtotal(
            $this->quantity,
            $this->unitPrice,
            $this->discountTotal,
            $this->taxTotal
        );

        return $this->subtotal;
    }

    /**
     * Menyelaraskan label currency seluruh nilai moneter item dengan currency
     * order induknya. Jumlah tidak diubah — hanya interpretasi currency-nya.
     */
    public function alignCurrency(string $currency): void
    {
        $this->unitPrice = $this->unitPrice->withCurrency($currency);
        $this->discountTotal = $this->discountTotal->withCurrency($currency);
        $this->taxTotal = $this->taxTotal->withCurrency($currency);
        $this->subtotal = $this->subtotal->withCurrency($currency);
    }
}
