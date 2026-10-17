# OrderEngine Library (`ttpryg/order-engine`)

`ttpryg/order-engine` is a framework-agnostic standalone PHP 8.1+ Order Engine library designed to handle order lifecycle management, product snapshot preservation, address management, append-only order history, domain events, order calculations, and PDO persistence cleanly without coupling to any specific framework or external domain.

---

## 🌟 1. Introduction

In modern ecommerce architectures, managing orders requires strict isolation of domain boundaries. An Order Engine must be responsible for storing historical order snapshots, calculating totals, enforcing valid status transitions, and recording audit histories without directly depending on inventory, payment gateways, promotion engines, or customer services.

`ttpryg/order-engine` provides a pure domain model and repository abstraction that can be seamlessly integrated into Vanilla PHP, Slim, Laravel, Symfony, CodeIgniter, or microservices.

---

## 🚀 2. Features

- **Framework Agnostic**: Pure PHP 8.1+ with zero framework dependencies.
- **Strict Domain Boundaries**: Uses generic references (`customer_type` / `customer_id`, `reference_type` / `reference_id`) instead of foreign key dependencies.
- **Product & Address Snapshotting**: Immutable historical data preservation at checkout time (`name`, `sku`, `unit_price`, shipping/billing addresses).
- **Strict Order Lifecycle**: Enforces valid status state machine transitions (`pending`, `confirmed`, `processing`, `completed`, `cancelled`, `refunded`).
- **Idempotent Operations**: Calling status transitions on already-matched targets avoids duplicate persistence or redundant events.
- **Append-Only History**: Immutable audit log for every important order action (`order_created`, `status_changed`, `item_added`, `item_removed`, `item_quantity_changed`, `address_changed`).
- **Domain Events**: Dispatches PSR-14 events (`OrderCreatedEvent`, `OrderStatusChangedEvent`, `OrderConfirmedEvent`, `OrderProcessingEvent`, `OrderCompletedEvent`, `OrderCancelledEvent`, `OrderRefundedEvent`).
- **Transaction Safety**: Supports atomic database transactions for creating and updating orders.
- **Money-Safe Calculations**: All monetary values are represented by the `Money` value object using integer minor units (no floating-point arithmetic), safe for payment gateway integration and financial audits.
- **Multiple Storage Drivers**: Clean separation via PDO (MariaDB, MySQL, SQLite) & In-Memory drivers for ultra-fast testing.

---

## 📦 3. Installation

Install via Composer:

```bash
composer require ttpryg/order-engine
```

---

## 🧬 4. Domain Model

The core entity is `Order`:

- `id`: Unique identifier (UUID/string).
- `order_number`: Unique human-readable reference (e.g. `ORD-20260922-A1B2C3`).
- `customer_type`: Generic customer reference type (e.g., `user`, `member`, `guest`).
- `customer_id`: Generic customer ID.
- `status`: `OrderStatus` Enum (`pending`, `confirmed`, `processing`, `completed`, `cancelled`, `refunded`).
- `currency`: Currency code (default: `IDR`).
- `subtotal`: Total of item subtotals (`Money`).
- `discount_total`: Total order discount applied (`Money`).
- `tax_total`: Total order tax applied (`Money`).
- `shipping_total`: Total shipping fee (`Money`).
- `grand_total`: Final amount (`subtotal - discount_total + tax_total + shipping_total`, `Money`).
- `note`: Customer or system note.
- `created_at` & `updated_at`: `DateTimeImmutable` timestamps.

---

## 🔄 5. Order Lifecycle

Order status transitions follow a strict state machine:

```
PENDING ──> CONFIRMED ──> PROCESSING ──> COMPLETED ──> REFUNDED
   │             │             │
   └─────────────┴─────────────┴───────> CANCELLED
```

Invalid transitions (e.g., `PENDING` directly to `COMPLETED` or `CANCELLED` to `CONFIRMED`) throw an `InvalidStatusTransitionException`.

---

## 🛒 6. Order Items

`OrderItem` captures a snapshot of the product at checkout:

- `id`, `order_id`, `product_id`, `variant_id`, `sku`
- `name`: Snapshot of product name
- `unit_price`: Snapshot of unit price (`Money`)
- `quantity`, `discount_total`, `tax_total`, `subtotal` (monetary fields are `Money`)

If product price or title changes in the catalog later, historical order items remain unaffected.

---

## 📬 7. Address

`OrderAddress` stores snapshot information for billing and shipping addresses:

- `type`: `OrderAddressType` Enum (`billing`, `shipping`).
- `name`, `phone`, `address`, `city`, `province`, `postal_code`, `country`.

---

## 🧮 8. Calculation

Order calculations are managed by `OrderCalculationService`:

$$\text{item\_subtotal} = (\text{quantity} \times \text{unit\_price}) - \text{discount\_total} + \text{tax\_total}$$

$$\text{grand\_total} = \max(0, \text{subtotal} - \text{discount\_total} + \text{tax\_total} + \text{shipping\_total})$$

All calculations run on the `Money` value object (integer minor units), so results are exact and never suffer from floating-point drift:

```php
use Ttpryg\OrderEngine\ValueObjects\Money;

$unitPrice = Money::fromDecimal(15000000);   // Rp 15.000.000,00
$total     = $unitPrice->multiply(2);        // Rp 30.000.000,00

$unitPrice->toDecimal();   // "15000000.00" — untuk kolom DECIMAL / payment gateway
$unitPrice->minorUnits();  // 1500000000
$unitPrice->toFloat();     // 15000000.0 — hanya untuk display/logging
```

`Money::fromDecimal()` accepts `Money`, `int`, `float`, and `string` inputs; inputs are normalized immediately into exact integer minor units. Mixed-currency arithmetic throws an `InvalidArgumentException`.

---

## 📜 9. Order History

Every change appends an immutable entry to `order_histories`:

- `action`: `OrderHistoryAction` (`order_created`, `status_changed`, `item_added`, `item_removed`, `item_quantity_changed`, `address_changed`).
- `from_status`, `to_status`, `reference_type`, `reference_id`, `note`, `actor_id`, `created_at`.

Histories are append-only; old records are never mutated or updated.

---

## 📢 10. Events

The package emits the following PSR-14 events:

- `OrderCreatedEvent`
- `OrderStatusChangedEvent`
- `OrderConfirmedEvent`
- `OrderProcessingEvent`
- `OrderCompletedEvent`
- `OrderCancelledEvent`
- `OrderRefundedEvent`

---

## 📂 11. Repository Abstraction

Repository interfaces are decoupled in `Ttpryg\OrderEngine\Contracts`:

- `OrderRepositoryInterface`
- `OrderItemRepositoryInterface`
- `OrderAddressRepositoryInterface`
- `OrderHistoryRepositoryInterface`

Both In-Memory (`MemoryOrderRepository`, etc.) and PDO implementations (`PdoOrderRepository`, etc.) are provided.

---

## 🛢️ 12. PDO Usage

```php
use PDO;
use Ttpryg\OrderEngine\Repositories\PdoOrderRepository;
use Ttpryg\OrderEngine\Repositories\PdoOrderItemRepository;
use Ttpryg\OrderEngine\Repositories\PdoOrderAddressRepository;
use Ttpryg\OrderEngine\Repositories\PdoOrderHistoryRepository;
use Ttpryg\OrderEngine\Services\OrderService;
use Ttpryg\OrderEngine\Services\OrderStatusService;

$pdo = new PDO("mysql:host=127.0.0.1;dbname=order_db", "root", "secret");

$orderRepo   = new PdoOrderRepository($pdo);
$itemRepo    = new PdoOrderItemRepository($pdo);
$addressRepo = new PdoOrderAddressRepository($pdo);
$historyRepo = new PdoOrderHistoryRepository($pdo);

$orderService  = new OrderService($orderRepo, $itemRepo, $addressRepo, $historyRepo, pdo: $pdo);
$statusService = new OrderStatusService($orderRepo, $historyRepo);
```

---

## 🗄️ 13. Database Schema

Run `database/schema.sql`:

```sql
CREATE TABLE IF NOT EXISTS orders (
    id VARCHAR(36) PRIMARY KEY,
    order_number VARCHAR(50) NOT NULL UNIQUE,
    customer_type VARCHAR(50) NOT NULL,
    customer_id VARCHAR(100) NOT NULL,
    status VARCHAR(30) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'IDR',
    subtotal DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    discount_total DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    tax_total DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    shipping_total DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    grand_total DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    note TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_orders_customer ON orders (customer_type, customer_id);
CREATE INDEX IF NOT EXISTS idx_orders_status ON orders (status);
CREATE INDEX IF NOT EXISTS idx_orders_created_at ON orders (created_at);

CREATE TABLE IF NOT EXISTS order_items (
    id VARCHAR(36) PRIMARY KEY,
    order_id VARCHAR(36) NOT NULL,
    product_id VARCHAR(36) NOT NULL,
    variant_id VARCHAR(36) NULL,
    sku VARCHAR(100) NULL,
    name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(18,2) NOT NULL,
    discount_total DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    tax_total DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    subtotal DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_order_items_order_id ON order_items (order_id);

CREATE TABLE IF NOT EXISTS order_addresses (
    id VARCHAR(36) PRIMARY KEY,
    order_id VARCHAR(36) NOT NULL,
    type VARCHAR(30) NOT NULL,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(50) NOT NULL,
    address TEXT NOT NULL,
    city VARCHAR(100) NOT NULL,
    province VARCHAR(100) NOT NULL,
    postal_code VARCHAR(20) NOT NULL,
    country VARCHAR(100) NOT NULL DEFAULT 'ID',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_order_addresses_order_id ON order_addresses (order_id);
CREATE INDEX IF NOT EXISTS idx_order_addresses_type ON order_addresses (order_id, type);

CREATE TABLE IF NOT EXISTS order_histories (
    id VARCHAR(36) PRIMARY KEY,
    order_id VARCHAR(36) NOT NULL,
    action VARCHAR(50) NOT NULL,
    from_status VARCHAR(30) NULL,
    to_status VARCHAR(30) NULL,
    reference_type VARCHAR(50) NULL,
    reference_id VARCHAR(100) NULL,
    note TEXT NULL,
    actor_id VARCHAR(36) NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_order_histories_order_id ON order_histories (order_id);
```

---

## 🧪 14. Testing

Run PHPUnit test suite:

```bash
vendor/bin/phpunit
```

Or using Docker Compose:

```bash
docker compose up -d
docker compose exec app vendor/bin/phpunit
```

---

## 💡 15. Framework Integration Example

### Slim 4 / Vanilla PHP / Laravel Integration

```php
use Ttpryg\OrderEngine\Entities\OrderItem;
use Ttpryg\OrderEngine\Entities\OrderAddress;
use Ttpryg\OrderEngine\Enums\OrderAddressType;
use Ttpryg\OrderEngine\ValueObjects\Money;

// 1. Create a new Order
$item = new OrderItem(
    id: 'item-001',
    orderId: 'order-1001',
    productId: 'prod-88',
    sku: 'SKU-LAPTOP-01',
    name: 'Pro Laptop 15-inch',
    quantity: 1,
    unitPrice: Money::fromDecimal(15000000)
);

$shippingAddress = new OrderAddress(
    id: 'addr-001',
    orderId: 'order-1001',
    type: OrderAddressType::SHIPPING,
    name: 'Budi Santoso',
    phone: '081234567890',
    address: 'Jl. Sudirman No. 45',
    city: 'Jakarta Selatan',
    province: 'DKI Jakarta',
    postalCode: '12190'
);

$order = $orderService->createOrder(
    id: 'order-1001',
    customerType: 'user',
    customerId: 'usr-55',
    items: [$item],
    addresses: [$shippingAddress],
    shippingTotal: Money::fromDecimal(50000)
);

// Read the exact grand total (e.g. for a payment gateway invoice)
$paymentAmount = $order->grandTotal->toDecimal(); // "15050000.00"

// 2. Advance Order Lifecycle Status
$statusService->confirm('order-1001', actorId: 'admin-1');
$statusService->process('order-1001', actorId: 'system');
$statusService->complete('order-1001', actorId: 'system');
```

---

## 🎯 16. Design Principles

- **Single Responsibility**: Manages solely order lifecycle, snapshots, history, and calculations.
- **Zero Cross-Domain Dependencies**: Does not reserve stock, execute payment processing, generate invoices, or handle shipping logistics directly.
- **Append-Only Auditing**: Every status change or item modification appends to immutable history records.
- **Idempotency**: Prevents invalid duplicate state modifications.

---

## ⚠️ 17. Limitations

- Does not calculate promotional rules, coupons, or cart discounts automatically; discount values are received as values from external application layers.
- Does not interact with payment gateways or shipping couriers.

---

## 📄 18. License

MIT License.
