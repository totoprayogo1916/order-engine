<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Repositories;

use DateTimeImmutable;
use PDO;
use Ttpryg\OrderEngine\Contracts\OrderRepositoryInterface;
use Ttpryg\OrderEngine\Entities\Order;
use Ttpryg\OrderEngine\Enums\OrderStatus;
use Ttpryg\OrderEngine\Exceptions\ConcurrencyException;
use Ttpryg\OrderEngine\ValueObjects\Money;

class PdoOrderRepository implements OrderRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(Order $order): void
    {
        $existing = $this->findById($order->id);
        $isUpdate = $existing instanceof \Ttpryg\OrderEngine\Entities\Order;

        if ($isUpdate) {
            // Optimistic locking: hanya update jika versi di database masih sama.
            $sql = 'UPDATE orders SET order_number = :order_number, customer_type = :customer_type, customer_id = :customer_id, status = :status, currency = :currency, subtotal = :subtotal, discount_total = :discount_total, tax_total = :tax_total, shipping_total = :shipping_total, grand_total = :grand_total, note = :note, updated_at = :updated_at, version = version + 1 WHERE id = :id AND version = :version';
            $params = [
                'id' => $order->id,
                'version' => $order->version,
                'order_number' => $order->orderNumber,
                'customer_type' => $order->customerType,
                'customer_id' => $order->customerId,
                'status' => $order->status->value,
                'currency' => $order->currency,
                'subtotal' => $order->subtotal->toDecimal(),
                'discount_total' => $order->discountTotal->toDecimal(),
                'tax_total' => $order->taxTotal->toDecimal(),
                'shipping_total' => $order->shippingTotal->toDecimal(),
                'grand_total' => $order->grandTotal->toDecimal(),
                'note' => $order->note,
                'updated_at' => $order->updatedAt->format('Y-m-d H:i:s'),
            ];
        } else {
            $sql = 'INSERT INTO orders (id, order_number, customer_type, customer_id, status, currency, subtotal, discount_total, tax_total, shipping_total, grand_total, note, version, created_at, updated_at) VALUES (:id, :order_number, :customer_type, :customer_id, :status, :currency, :subtotal, :discount_total, :tax_total, :shipping_total, :grand_total, :note, :version, :created_at, :updated_at)';
            $params = [
                'id' => $order->id,
                'order_number' => $order->orderNumber,
                'customer_type' => $order->customerType,
                'customer_id' => $order->customerId,
                'status' => $order->status->value,
                'currency' => $order->currency,
                'subtotal' => $order->subtotal->toDecimal(),
                'discount_total' => $order->discountTotal->toDecimal(),
                'tax_total' => $order->taxTotal->toDecimal(),
                'shipping_total' => $order->shippingTotal->toDecimal(),
                'grand_total' => $order->grandTotal->toDecimal(),
                'note' => $order->note,
                'version' => $order->version,
                'created_at' => $order->createdAt->format('Y-m-d H:i:s'),
                'updated_at' => $order->updatedAt->format('Y-m-d H:i:s'),
            ];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        if ($isUpdate) {
            if ($stmt->rowCount() === 0) {
                throw ConcurrencyException::forOrder($order->id, $order->version);
            }

            $order->version++;
        }
    }

    public function findById(string $id): ?Order
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapToEntity($row) : null;
    }

    public function findByOrderNumber(string $orderNumber): ?Order
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE order_number = :order_number');
        $stmt->execute(['order_number' => $orderNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapToEntity($row) : null;
    }

    public function findByCustomer(string $customerType, string $customerId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE customer_type = :customer_type AND customer_id = :customer_id ORDER BY created_at DESC');
        $stmt->execute([
            'customer_type' => $customerType,
            'customer_id' => $customerId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map($this->mapToEntity(...), $rows);
    }

    public function delete(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM orders WHERE id = :id');

        return $stmt->execute(['id' => $id]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function mapToEntity(array $row): Order
    {
        $currency = (string) $row['currency'];

        return new Order(
            id: (string) $row['id'],
            orderNumber: (string) $row['order_number'],
            customerType: (string) $row['customer_type'],
            customerId: (string) $row['customer_id'],
            status: OrderStatus::from((string) $row['status']),
            currency: $currency,
            subtotal: Money::fromDatabaseValue($row['subtotal'], $currency),
            discountTotal: Money::fromDatabaseValue($row['discount_total'], $currency),
            taxTotal: Money::fromDatabaseValue($row['tax_total'], $currency),
            shippingTotal: Money::fromDatabaseValue($row['shipping_total'], $currency),
            grandTotal: Money::fromDatabaseValue($row['grand_total'], $currency),
            note: $row['note'] ?? null,
            createdAt: ! empty($row['created_at']) ? new DateTimeImmutable($row['created_at']) : null,
            updatedAt: ! empty($row['updated_at']) ? new DateTimeImmutable($row['updated_at']) : null,
            version: isset($row['version']) ? (int) $row['version'] : 1
        );
    }
}
