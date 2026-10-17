# Changelog

All notable changes to `ttpryg/order-engine` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `Money` value object rewritten as an immutable, integer-based (minor units) monetary type:
  - Factories: `Money::ofMinor()`, `Money::fromDecimal()`, `Money::from()`, `Money::fromDatabaseValue()`, `Money::zero()`.
  - Arithmetic: `add()`, `subtract()`, `multiply()`, `min()`, `max()` (all pure integer, mixed-currency operations throw `InvalidArgumentException`).
  - Introspection & conversion: `minorUnits()`, `toDecimal()` (safe for `DECIMAL` columns / payment gateways), `toFloat()` (display only), `equals()`, `compareTo()`, `isZero()`, `isNegative()`, `withCurrency()`, `resolveCurrency()`.
- `OrderItem::alignCurrency()` to align an item's monetary labels with its parent order's currency.
- New `tests/Unit/MoneyTest.php` plus precision regression tests (e.g. `3 × 0.1` yields exactly `"0.30"`).
- `OrderStatusService` now accepts an optional `PDO` connection (`new OrderStatusService($orderRepo, $historyRepo, $dispatcher, $pdo)`); status transitions persist atomically.
- Shared trait `Ttpryg\OrderEngine\Services\Concerns\ExecutesTransactions` providing `executeInTransaction()` for both `OrderService` and `OrderStatusService`.
- New `tests/Integration/OrderStatusServiceTransactionTest.php` covering atomic commit and rollback of status transitions (status update + audit history).
- Optimistic locking for the `orders` table: new `version INT NOT NULL DEFAULT 1` column, `Order::$version` (normalized to at least `1`), and new `Ttpryg\OrderEngine\Exceptions\ConcurrencyException` thrown when an update targets a stale version.
- New `tests/Integration/OptimisticLockingTest.php` covering version hydration, version increments, stale-copy rejection, and status-transition version bumps.

### Changed

- **Breaking**: `Order` monetary properties (`$subtotal`, `$discountTotal`, `$taxTotal`, `$shippingTotal`, `$grandTotal`) and `OrderItem` monetary properties (`$unitPrice`, `$discountTotal`, `$taxTotal`, `$subtotal`) are now `Money` instead of `float`. Use `->toDecimal()`, `->minorUnits()`, or `->toFloat()` to read values.
- **Breaking**: `OrderCalculationService::calculateItemSubtotal()`, `calculateSubtotal()`, and `calculateGrandTotal()` now accept and return `Money` (scalar `int|float|string` inputs are still accepted and normalized).
- **Breaking**: `OrderItem::calculateSubtotal()` now returns `Money` (previously `float`); item subtotals are clamped to zero, matching `OrderCalculationService` semantics.
- `OrderService::createOrder()` parameters `$discountTotal`, `$taxTotal`, `$shippingTotal` now accept `Money|int|float|string` (was `float`); scalar inputs are normalized to `Money`.
- `Order` and `OrderItem` constructors accept `Money|int|float|string|null` for monetary arguments and normalize them to `Money` immediately, eliminating floating-point arithmetic on the financial path.
- `Order::recalculateTotals()` and `OrderItem::calculateSubtotal()` now delegate all formulas to `OrderCalculationService` (single source of truth) and align item currencies with the order currency.
- PDO repositories persist monetary values via `Money::toDecimal()` and hydrate them via `Money::fromDatabaseValue()` (exact parsing for MySQL decimal strings and SQLite floats).
- `OrderStatusService::changeStatus()` (and `confirm()`, `process()`, `complete()`, `cancel()`, `refund()`) now wraps the order update and the `order_histories` insert in a single database transaction when a `PDO` instance is provided — a failed history write rolls back the status change, preserving the audit trail. Domain events are dispatched only after a successful commit.
- `OrderService` and `OrderStatusService` now share transaction handling via the `ExecutesTransactions` trait instead of each defining their own copy; a `PDO` connection that is already inside a transaction is respected (the outer scope controls commit/rollback).
- `PdoOrderRepository::save()` now issues `UPDATE orders SET ..., version = version + 1 WHERE id = :id AND version = :version` and throws `ConcurrencyException` when `rowCount() === 0` (the order was modified by another process); on success the in-memory `Order::$version` is incremented. Inserts persist the initial version.
- `MemoryOrderRepository::save()` increments `Order::$version` on subsequent saves of an existing order to keep version semantics consistent with the PDO driver.
- **Schema change**: `database/schema.sql` adds `version INT NOT NULL DEFAULT 1` to the `orders` table. Existing databases need `ALTER TABLE orders ADD COLUMN version INT NOT NULL DEFAULT 1;` before upgrading.

### Fixed

- Floating-point precision drift in order/item totals and in unit/integration test assertions.
- `PdoOrderRepository` trailing-whitespace formatting issues; added `array<string, mixed>` PHPDoc on PDO `mapToEntity()` methods for the order and order-item repositories.

## [1.0.0] - 2026-09-22

### Added

- Initial release: order lifecycle with strict status state machine, order items & address snapshots, append-only order history, PSR-14 domain events, `OrderCalculationService`, repository abstraction with PDO (MySQL/MariaDB/SQLite) and in-memory drivers, and database transaction support in `OrderService`.
