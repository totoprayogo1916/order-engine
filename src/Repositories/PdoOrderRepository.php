<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Repositories;

use DateTimeImmutable;
use PDO;
use PDOException;
use Ttpryg\OrderEngine\Contracts\OrderRepositoryInterface;
use Ttpryg\OrderEngine\Entities\Order;
use Ttpryg\OrderEngine\Enums\OrderStatus;
use Ttpryg\OrderEngine\Exceptions\ConcurrencyException;
use Ttpryg\OrderEngine\Repositories\Concerns\NormalizesPagination;
use Ttpryg\OrderEngine\Repositories\Concerns\ResolvesDatabaseDriver;
use Ttpryg\OrderEngine\ValueObjects\Money;
use Ttpryg\OrderEngine\ValueObjects\OrderFilter;
use Ttpryg\OrderEngine\ValueObjects\PaginatedResult;

class PdoOrderRepository implements OrderRepositoryInterface
{
    use NormalizesPagination;
    use ResolvesDatabaseDriver;

    public function __construct(private readonly PDO $pdo)
    {
        $this->driver = $this->resolveDriver($pdo);
    }

    public function save(Order $order): void
    {
        if ($this->driver === 'mysql') {
            $this->saveUsingDuplicateKeyUpdate($order);

            return;
        }

        $this->saveUsingUpdateFirst($order);
    }

    /**
     * Satu-statement upsert (MySQL/MariaDB) — tanpa SELECT sebelum tulis.
     *
     * Guard `id = IF(id = :guard_id AND version = :guard_version, id, NULL)` membuat
     * statement gagal keras saat versi basi atau unique key lain (`order_number`)
     * bentrok, sehingga optimistic locking terjaga dan baris lain tidak tertimpa.
     * Deteksi hasil: 1 = insert, 2 = update.
     */
    private function saveUsingDuplicateKeyUpdate(Order $order): void
    {
        $sql = 'INSERT INTO orders (id, order_number, customer_type, customer_id, status, currency, subtotal, discount_total, tax_total, shipping_total, grand_total, note, version, created_at, updated_at) '
            .'VALUES (:id, :order_number, :customer_type, :customer_id, :status, :currency, :subtotal, :discount_total, :tax_total, :shipping_total, :grand_total, :note, :version, :created_at, :updated_at) '
            .'ON DUPLICATE KEY UPDATE '
            .'id = IF(id = :guard_id AND version = :guard_version, id, NULL), '
            .'order_number = VALUES(order_number), '
            .'customer_type = VALUES(customer_type), '
            .'customer_id = VALUES(customer_id), '
            .'status = VALUES(status), '
            .'currency = VALUES(currency), '
            .'subtotal = VALUES(subtotal), '
            .'discount_total = VALUES(discount_total), '
            .'tax_total = VALUES(tax_total), '
            .'shipping_total = VALUES(shipping_total), '
            .'grand_total = VALUES(grand_total), '
            .'note = VALUES(note), '
            .'updated_at = VALUES(updated_at), '
            .'version = version + 1';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->insertParams($order) + [
                'guard_id' => $order->id,
                'guard_version' => $order->version,
            ]);
        } catch (PDOException $exception) {
            // Guard memicu error ketika versi basi ATAU unique key lain bentrok.
            if ($this->findById($order->id) instanceof Order) {
                throw ConcurrencyException::forOrder($order->id, $order->version);
            }

            throw $exception;
        }

        if ($stmt->rowCount() === 2) {
            $order->version++;
        }
    }

    /**
     * Jalur SQLite & PostgreSQL: UPDATE berbasis versi lebih dulu (jalur tersering
     * untuk order yang sudah ada — satu statement, tanpa SELECT); ketika rowCount 0
     * langsung INSERT. Duplikasi PK (versi basi) baru terdeteksi dari error INSERT,
     * dan SELECT hanya dipakai untuk mengklasifikasi error tersebut.
     */
    private function saveUsingUpdateFirst(Order $order): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE orders SET order_number = :order_number, customer_type = :customer_type, customer_id = :customer_id, status = :status, currency = :currency, subtotal = :subtotal, discount_total = :discount_total, tax_total = :tax_total, shipping_total = :shipping_total, grand_total = :grand_total, note = :note, updated_at = :updated_at, version = version + 1 WHERE id = :id AND version = :version'
        );
        $stmt->execute($this->updateParams($order));

        if ($stmt->rowCount() > 0) {
            $order->version++;

            return;
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO orders (id, order_number, customer_type, customer_id, status, currency, subtotal, discount_total, tax_total, shipping_total, grand_total, note, version, created_at, updated_at) VALUES (:id, :order_number, :customer_type, :customer_id, :status, :currency, :subtotal, :discount_total, :tax_total, :shipping_total, :grand_total, :note, :version, :created_at, :updated_at)'
            );
            $stmt->execute($this->insertParams($order));
        } catch (PDOException $exception) {
            // INSERT gagal: baris sudah ada tetapi tidak ter-update (versi basi)
            // ATAU unique key lain (order_number) bentrok.
            if ($this->findById($order->id) instanceof Order) {
                throw ConcurrencyException::forOrder($order->id, $order->version);
            }

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function updateParams(Order $order): array
    {
        return [
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
    }

    /**
     * @return array<string, mixed>
     */
    private function insertParams(Order $order): array
    {
        return $this->updateParams($order) + [
            'created_at' => $order->createdAt->format('Y-m-d H:i:s'),
        ];
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

    public function paginateByCustomer(
        string $customerType,
        string $customerId,
        int $page = 1,
        int $perPage = 20,
        ?OrderFilter $filter = null
    ): PaginatedResult {
        [$page, $perPage] = $this->normalizePagination($page, $perPage);
        $filter ??= new OrderFilter;

        [$where, $params] = $this->buildPaginateClauses($customerType, $customerId, $filter);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM orders WHERE {$where}");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 0;
        $offset = ($page - 1) * $perPage;

        // LIMIT/OFFSET diinterpolasi sebagai literal — keduanya sudah dijamin
        // integer oleh normalizePagination().
        $stmt = $this->pdo->prepare("SELECT * FROM orders WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);

        return new PaginatedResult(
            items: array_map($this->mapToEntity(...), $stmt->fetchAll(PDO::FETCH_ASSOC)),
            page: $page,
            perPage: $perPage,
            total: $total,
            totalPages: $totalPages
        );
    }

    /**
     * Klausa WHERE + parameter terikat untuk paginateByCustomer.
     * Semua kondisi filter bersifat AND; properti null di OrderFilter diabaikan.
     *
     * @return array{string, array<string, mixed>}
     */
    private function buildPaginateClauses(string $customerType, string $customerId, OrderFilter $filter): array
    {
        $where = 'customer_type = :customer_type AND customer_id = :customer_id';
        $params = [
            'customer_type' => $customerType,
            'customer_id' => $customerId,
        ];

        if ($filter->status instanceof \Ttpryg\OrderEngine\Enums\OrderStatus) {
            $where .= ' AND status = :status';
            $params['status'] = $filter->status->value;
        }

        if ($filter->paymentStatus instanceof \Ttpryg\OrderEngine\Enums\PaymentStatus) {
            $placeholders = [];
            foreach ($filter->paymentStatus->orderStatuses() as $index => $status) {
                $key = "payment_status_{$index}";
                $placeholders[] = ":{$key}";
                $params[$key] = $status->value;
            }
            $where .= ' AND status IN ('.implode(', ', $placeholders).')';
        }

        if ($filter->createdFrom instanceof \DateTimeImmutable) {
            $where .= ' AND created_at >= :created_from';
            $params['created_from'] = $filter->createdFrom->format('Y-m-d H:i:s');
        }

        if ($filter->createdTo instanceof \DateTimeImmutable) {
            $where .= ' AND created_at <= :created_to';
            $params['created_to'] = $filter->createdTo->format('Y-m-d H:i:s');
        }

        return [$where, $params];
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
