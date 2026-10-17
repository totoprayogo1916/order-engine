<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Entities;

use DateTimeImmutable;
use Ttpryg\OrderEngine\Enums\OrderStatus;
use Ttpryg\OrderEngine\Services\OrderCalculationService;
use Ttpryg\OrderEngine\ValueObjects\Money;

class Order
{
    public OrderStatus $status;

    private Money $subtotal;

    private readonly Money $discountTotal;

    private readonly Money $taxTotal;

    private readonly Money $shippingTotal;

    private Money $grandTotal;

    /** @var OrderItem[] */
    public array $items = [];

    /** @var OrderAddress[] */
    public array $addresses = [];

    private ?OrderCalculationService $calculationService = null;

    public function __construct(
        public readonly string $id,
        public readonly string $orderNumber,
        public readonly string $customerType,
        public readonly string $customerId,
        OrderStatus|string $status = OrderStatus::PENDING,
        public string $currency = 'IDR',
        Money|int|float|string|null $subtotal = null,
        Money|int|float|string|null $discountTotal = null,
        Money|int|float|string|null $taxTotal = null,
        Money|int|float|string|null $shippingTotal = null,
        Money|int|float|string|null $grandTotal = null,
        public ?string $note = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
        public int $version = 1
    ) {
        $this->status = is_string($status) ? OrderStatus::from($status) : $status;
        $this->version = max(1, $version);
        $this->subtotal = Money::from($subtotal ?? 0, $this->currency);
        $this->discountTotal = Money::from($discountTotal ?? 0, $this->currency);
        $this->taxTotal = Money::from($taxTotal ?? 0, $this->currency);
        $this->shippingTotal = Money::from($shippingTotal ?? 0, $this->currency);
        $this->grandTotal = Money::from($grandTotal ?? 0, $this->currency);
        $this->createdAt = $createdAt ?? new DateTimeImmutable;
        $this->updatedAt = $updatedAt ?? new DateTimeImmutable;
    }

    public function addItem(OrderItem $item): void
    {
        $this->items[] = $item;
        $this->recalculateTotals();
        $this->updatedAt = new DateTimeImmutable;
    }

    public function removeItem(string $itemId): void
    {
        $this->items = array_values(array_filter(
            $this->items,
            fn (OrderItem $item) => $item->id !== $itemId
        ));
        $this->recalculateTotals();
        $this->updatedAt = new DateTimeImmutable;
    }

    public function subtotal(): Money
    {
        return $this->subtotal;
    }

    public function discountTotal(): Money
    {
        return $this->discountTotal;
    }

    public function taxTotal(): Money
    {
        return $this->taxTotal;
    }

    public function shippingTotal(): Money
    {
        return $this->shippingTotal;
    }

    public function grandTotal(): Money
    {
        return $this->grandTotal;
    }

    public function updateItemQuantity(string $itemId, int $quantity): void
    {
        foreach ($this->items as $item) {
            if ($item->id === $itemId) {
                // Guard invariant kuantitas >= 1 hidup di OrderItem::updateQuantity().
                $item->updateQuantity($quantity);
                break;
            }
        }
        $this->recalculateTotals();
        $this->updatedAt = new DateTimeImmutable;
    }

    /**
     * Mengganti kalkulator khusus (mis. aturan pembulatan/fee custom) untuk order ini.
     * OrderService men-inject kalkulator yang sama ke setiap order yang di-hydrate.
     */
    public function setCalculationService(OrderCalculationService $calculationService): self
    {
        $this->calculationService = $calculationService;

        return $this;
    }

    public function recalculateTotals(): void
    {
        foreach ($this->items as $item) {
            $item->alignCurrency($this->currency);
        }

        $calculator = $this->calculationService();
        $this->subtotal = $calculator->calculateSubtotal($this->items, $this->currency);
        $this->grandTotal = $calculator->calculateGrandTotal(
            $this->subtotal,
            $this->discountTotal,
            $this->taxTotal,
            $this->shippingTotal
        );
    }

    private function calculationService(): OrderCalculationService
    {
        return $this->calculationService ??= new OrderCalculationService;
    }
}
