<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Entities;

use DateTimeImmutable;
use InvalidArgumentException;
use Ttpryg\OrderEngine\Services\OrderCalculationService;
use Ttpryg\OrderEngine\ValueObjects\Money;

class OrderItem
{
    private Money $unitPrice;

    private Money $discountTotal;

    private Money $taxTotal;

    private Money $subtotal;

    private int $quantity;

    private ?OrderCalculationService $calculationService = null;

    public function __construct(
        public readonly string $id,
        public readonly string $orderId,
        public readonly string $productId,
        public ?string $variantId = null,
        public ?string $sku = null,
        public string $name = '',
        int $quantity = 1,
        Money|int|float|string|null $unitPrice = null,
        Money|int|float|string|null $discountTotal = null,
        Money|int|float|string|null $taxTotal = null,
        Money|int|float|string|null $subtotal = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null
    ) {
        if ($quantity < 1) {
            throw new InvalidArgumentException("Order item quantity must be at least 1, got {$quantity}.");
        }
        $this->quantity = $quantity;

        $currency = Money::resolveCurrency($unitPrice, $discountTotal, $taxTotal, $subtotal);
        $this->unitPrice = Money::from($unitPrice ?? 0, $currency);
        $this->discountTotal = Money::from($discountTotal ?? 0, $currency);
        $this->taxTotal = Money::from($taxTotal ?? 0, $currency);
        $this->subtotal = Money::from($subtotal ?? 0, $currency);
        $this->createdAt = $createdAt ?? new DateTimeImmutable;
        $this->updatedAt = $updatedAt ?? new DateTimeImmutable;

        if ($this->subtotal->isZero() && ! $this->unitPrice->isZero()) {
            $this->calculateSubtotal();
        }
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function unitPrice(): Money
    {
        return $this->unitPrice;
    }

    public function discountTotal(): Money
    {
        return $this->discountTotal;
    }

    public function taxTotal(): Money
    {
        return $this->taxTotal;
    }

    public function subtotal(): Money
    {
        return $this->subtotal;
    }

    /**
     * Mengubah kuantitas item — satu-satunya jalur mutasi kuantitas setelah
     * konstruksi. Invariant kuantitas >= 1 dijaga dengan guard assertion;
     * subtotal ikut dihitung ulang.
     */
    public function updateQuantity(int $quantity): void
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException("Order item quantity must be at least 1, got {$quantity}.");
        }

        $this->quantity = $quantity;
        $this->calculateSubtotal();
        $this->updatedAt = new DateTimeImmutable;
    }

    public function calculateSubtotal(): Money
    {
        $this->subtotal = $this->calculationService()->calculateItemSubtotal(
            $this->quantity,
            $this->unitPrice,
            $this->discountTotal,
            $this->taxTotal
        );

        return $this->subtotal;
    }

    /**
     * Menetapkan subtotal hasil kalkulasi. Hanya untuk OrderCalculationService —
     * satu-satunya jalur tulis subtotal setelah konstruksi.
     */
    public function setSubtotal(Money $subtotal): void
    {
        $this->subtotal = $subtotal;
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

    private function calculationService(): OrderCalculationService
    {
        return $this->calculationService ??= new OrderCalculationService;
    }
}
