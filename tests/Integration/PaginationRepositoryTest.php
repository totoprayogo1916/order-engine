<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ttpryg\OrderEngine\Entities\Order;
use Ttpryg\OrderEngine\Enums\OrderStatus;
use Ttpryg\OrderEngine\Enums\PaymentStatus;
use Ttpryg\OrderEngine\Repositories\MemoryOrderRepository;
use Ttpryg\OrderEngine\Repositories\PdoOrderRepository;
use Ttpryg\OrderEngine\Tests\Support\QueryLogPdo;
use Ttpryg\OrderEngine\ValueObjects\OrderFilter;
use Ttpryg\OrderEngine\ValueObjects\PaginatedResult;

/**
 * Setiap kasus dijalankan terhadap MemoryOrderRepository DAN PdoOrderRepository
 * (sqlite) untuk memastikan paritas perilaku paginasi + filtering antar driver.
 */
class PaginationRepositoryTest extends TestCase
{
    private MemoryOrderRepository $memoryRepo;

    private PdoOrderRepository $pdoRepo;

    private QueryLogPdo $pdo;

    protected function setUp(): void
    {
        $this->memoryRepo = new MemoryOrderRepository;
        $this->pdo = new QueryLogPdo('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(file_get_contents(__DIR__.'/../../database/schema.sql'));
        $this->pdoRepo = new PdoOrderRepository($this->pdo);

        foreach ($this->seedOrders() as $order) {
            $this->memoryRepo->save($order);
            $this->pdoRepo->save($order);
        }
    }

    /** @return list<Order> */
    private function seedOrders(): array
    {
        $make = fn (string $id, OrderStatus $status, string $createdAt, string $customerId = 'user-1', string $customerType = 'user') => new Order(
            id: $id,
            orderNumber: strtoupper("ORD-{$id}"),
            customerType: $customerType,
            customerId: $customerId,
            status: $status,
            createdAt: new DateTimeImmutable($createdAt),
            updatedAt: new DateTimeImmutable($createdAt)
        );

        return [
            $make('ord-p1', OrderStatus::PENDING, '2026-01-01 10:00:00'),
            $make('ord-p2', OrderStatus::CONFIRMED, '2026-01-02 10:00:00'),
            $make('ord-p3', OrderStatus::COMPLETED, '2026-01-03 10:00:00'),
            $make('ord-p4', OrderStatus::CANCELLED, '2026-01-04 10:00:00'),
            $make('ord-p5', OrderStatus::REFUNDED, '2026-01-05 10:00:00'),
            $make('ord-p6', OrderStatus::PENDING, '2026-01-06 10:00:00', 'user-2'),
            $make('ord-p7', OrderStatus::PENDING, '2026-01-07 10:00:00', 'user-1', 'guest'),
            // Dua order dengan created_at sama — menguji pemecah seri id DESC.
            $make('ord-p8', OrderStatus::PENDING, '2026-01-08 10:00:00'),
            $make('ord-p9', OrderStatus::PENDING, '2026-01-08 10:00:00'),
        ];
    }

    public function test_paginates_in_descending_created_order_with_correct_metadata(): void
    {
        $page1 = $this->assertParity(1, 3);
        $this->assertSame(['ord-p9', 'ord-p8', 'ord-p5'], $this->idsOf($page1));
        $this->assertSame(7, $page1->total);
        $this->assertSame(3, $page1->totalPages);
        $this->assertTrue($page1->hasMore());

        $page2 = $this->assertParity(2, 3);
        $this->assertSame(['ord-p4', 'ord-p3', 'ord-p2'], $this->idsOf($page2));

        $page3 = $this->assertParity(3, 3);
        $this->assertSame(['ord-p1'], $this->idsOf($page3));
        $this->assertFalse($page3->hasMore());
    }

    public function test_filters_by_order_status(): void
    {
        $result = $this->assertParity(1, 20, new OrderFilter(status: OrderStatus::PENDING));

        $this->assertSame(['ord-p9', 'ord-p8', 'ord-p1'], $this->idsOf($result));
        $this->assertSame(3, $result->total);
        $this->assertSame(1, $result->totalPages);
    }

    public function test_filters_by_payment_status(): void
    {
        $paid = $this->assertParity(1, 20, new OrderFilter(paymentStatus: PaymentStatus::PAID));
        $this->assertSame(['ord-p3', 'ord-p2'], $this->idsOf($paid));

        $unpaid = $this->assertParity(1, 20, new OrderFilter(paymentStatus: PaymentStatus::UNPAID));
        $this->assertSame(['ord-p9', 'ord-p8', 'ord-p4', 'ord-p1'], $this->idsOf($unpaid));

        $refunded = $this->assertParity(1, 20, new OrderFilter(paymentStatus: PaymentStatus::REFUNDED));
        $this->assertSame(['ord-p5'], $this->idsOf($refunded));
    }

    public function test_filters_by_created_at_range(): void
    {
        $result = $this->assertParity(1, 20, new OrderFilter(
            createdFrom: new DateTimeImmutable('2026-01-02 00:00:00'),
            createdTo: new DateTimeImmutable('2026-01-04 23:59:59')
        ));

        $this->assertSame(['ord-p4', 'ord-p3', 'ord-p2'], $this->idsOf($result));
    }

    public function test_combines_multiple_filters_with_and_semantics(): void
    {
        $result = $this->assertParity(1, 20, new OrderFilter(
            status: OrderStatus::PENDING,
            createdFrom: new DateTimeImmutable('2026-01-08 00:00:00'),
            paymentStatus: PaymentStatus::UNPAID
        ));

        $this->assertSame(['ord-p9', 'ord-p8'], $this->idsOf($result));

        // Kondisi yang saling bertentangan → hasil kosong, bukan error.
        $contradictory = $this->assertParity(1, 20, new OrderFilter(
            status: OrderStatus::PENDING,
            paymentStatus: PaymentStatus::PAID
        ));
        $this->assertTrue($contradictory->isEmpty());
    }

    public function test_normalizes_page_and_per_page(): void
    {
        $zeroPage = $this->assertParity(0, 0);
        $this->assertSame(1, $zeroPage->page);
        $this->assertSame(1, $zeroPage->perPage);

        $hugePerPage = $this->assertParity(1, 500);
        $this->assertSame(100, $hugePerPage->perPage);
        $this->assertCount(7, $hugePerPage->items);
    }

    public function test_empty_result_has_zero_metadata(): void
    {
        $memory = $this->memoryRepo->paginateByCustomer('user', 'user-does-not-exist');
        $pdo = $this->pdoRepo->paginateByCustomer('user', 'user-does-not-exist');

        foreach ([$memory, $pdo] as $result) {
            $this->assertTrue($result->isEmpty());
            $this->assertSame(0, $result->total);
            $this->assertSame(0, $result->totalPages);
            $this->assertFalse($result->hasMore());
        }
    }

    public function test_pdo_issues_only_count_and_select_queries(): void
    {
        $this->pdo->resetQueryLog();
        $this->pdoRepo->paginateByCustomer('user', 'user-1', 1, 2, new OrderFilter(status: OrderStatus::PENDING));

        $this->assertCount(2, $this->pdo->queries);
        $this->assertStringStartsWith('SELECT COUNT(*)', $this->pdo->queries[0]);
        $this->assertStringStartsWith('SELECT * FROM orders', $this->pdo->queries[1]);
        $this->assertStringContainsString('LIMIT 2 OFFSET 0', $this->pdo->queries[1]);
    }

    /**
     * Menjalankan kueri yang sama di memory dan PDO, memastikan paritas,
     * lalu mengembalikan hasil PDO.
     *
     * @return PaginatedResult<Order>
     */
    private function assertParity(int $page, int $perPage, ?OrderFilter $filter = null): PaginatedResult
    {
        $memory = $this->memoryRepo->paginateByCustomer('user', 'user-1', $page, $perPage, $filter);
        $pdo = $this->pdoRepo->paginateByCustomer('user', 'user-1', $page, $perPage, $filter);

        $this->assertSame($this->idsOf($memory), $this->idsOf($pdo));
        $this->assertSame($memory->total, $pdo->total);
        $this->assertSame($memory->totalPages, $pdo->totalPages);
        $this->assertSame($memory->page, $pdo->page);
        $this->assertSame($memory->perPage, $pdo->perPage);

        return $pdo;
    }

    /**
     * @param  PaginatedResult<Order>  $result
     * @return list<string>
     */
    private function idsOf(PaginatedResult $result): array
    {
        return array_map(static fn (Order $order) => $order->id, $result->items);
    }
}
