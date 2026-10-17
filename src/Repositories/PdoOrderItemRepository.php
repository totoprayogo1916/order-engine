<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Repositories;

use DateTimeImmutable;
use PDO;
use Ttpryg\OrderEngine\Contracts\OrderItemRepositoryInterface;
use Ttpryg\OrderEngine\Entities\OrderItem;
use Ttpryg\OrderEngine\ValueObjects\Money;

class PdoOrderItemRepository implements OrderItemRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(OrderItem $item): void
    {
        $existing = $this->findById($item->id);

        $sql = $existing instanceof \Ttpryg\OrderEngine\Entities\OrderItem
            ? 'UPDATE order_items SET order_id = :order_id, product_id = :product_id, variant_id = :variant_id, sku = :sku, name = :name, quantity = :quantity, unit_price = :unit_price, discount_total = :discount_total, tax_total = :tax_total, subtotal = :subtotal, updated_at = :updated_at WHERE id = :id'
            : 'INSERT INTO order_items (id, order_id, product_id, variant_id, sku, name, quantity, unit_price, discount_total, tax_total, subtotal, created_at, updated_at) VALUES (:id, :order_id, :product_id, :variant_id, :sku, :name, :quantity, :unit_price, :discount_total, :tax_total, :subtotal, :created_at, :updated_at)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $item->id,
            'order_id' => $item->orderId,
            'product_id' => $item->productId,
            'variant_id' => $item->variantId,
            'sku' => $item->sku,
            'name' => $item->name,
            'quantity' => $item->quantity,
            'unit_price' => $item->unitPrice->toDecimal(),
            'discount_total' => $item->discountTotal->toDecimal(),
            'tax_total' => $item->taxTotal->toDecimal(),
            'subtotal' => $item->subtotal->toDecimal(),
            'created_at' => $item->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $item->updatedAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function findById(string $id): ?OrderItem
    {
        $stmt = $this->pdo->prepare('SELECT * FROM order_items WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapToEntity($row) : null;
    }

    public function findByOrderId(string $orderId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM order_items WHERE order_id = :order_id ORDER BY created_at ASC');
        $stmt->execute(['order_id' => $orderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map($this->mapToEntity(...), $rows);
    }

    public function delete(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM order_items WHERE id = :id');

        return $stmt->execute(['id' => $id]);
    }

    public function deleteByOrderId(string $orderId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM order_items WHERE order_id = :order_id');

        return $stmt->execute(['order_id' => $orderId]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function mapToEntity(array $row): OrderItem
    {
        return new OrderItem(
            id: (string) $row['id'],
            orderId: (string) $row['order_id'],
            productId: (string) $row['product_id'],
            variantId: $row['variant_id'] ?? null,
            sku: $row['sku'] ?? null,
            name: (string) $row['name'],
            quantity: (int) $row['quantity'],
            unitPrice: Money::fromDatabaseValue($row['unit_price']),
            discountTotal: Money::fromDatabaseValue($row['discount_total']),
            taxTotal: Money::fromDatabaseValue($row['tax_total']),
            subtotal: Money::fromDatabaseValue($row['subtotal']),
            createdAt: ! empty($row['created_at']) ? new DateTimeImmutable($row['created_at']) : null,
            updatedAt: ! empty($row['updated_at']) ? new DateTimeImmutable($row['updated_at']) : null
        );
    }
}
