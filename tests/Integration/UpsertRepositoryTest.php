<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Ttpryg\OrderEngine\Entities\Order;
use Ttpryg\OrderEngine\Entities\OrderItem;
use Ttpryg\OrderEngine\Repositories\PdoOrderItemRepository;
use Ttpryg\OrderEngine\Repositories\PdoOrderRepository;
use Ttpryg\OrderEngine\Tests\Support\QueryLogPdo;

class UpsertRepositoryTest extends TestCase
{
    private QueryLogPdo $pdo;

    private PdoOrderRepository $orderRepo;

    private PdoOrderItemRepository $itemRepo;

    protected function setUp(): void
    {
        $this->pdo = new QueryLogPdo('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(file_get_contents(__DIR__.'/../../database/schema.sql'));

        $this->orderRepo = new PdoOrderRepository($this->pdo);
        $this->itemRepo = new PdoOrderItemRepository($this->pdo);

        $this->orderRepo->save(new Order(
            id: 'ord-upsert-1',
            orderNumber: 'ORD-UPSERT-001',
            customerType: 'user',
            customerId: 'user-upsert-1'
        ));
        $this->pdo->resetQueryLog();
    }

    public function test_item_save_uses_single_statement_without_select_before_write(): void
    {
        $item = $this->makeItem('item-upsert-1', 'Original Name');

        $this->itemRepo->save($item);

        $this->assertCount(1, $this->pdo->queries);
        $this->assertStringStartsWith('INSERT INTO order_items', $this->pdo->queries[0]);

        // Save kedua (update) tetap satu statement upsert, tanpa SELECT.
        $this->pdo->resetQueryLog();
        $item->name = 'Renamed Name';
        $item->updatedAt = new \DateTimeImmutable;
        $this->itemRepo->save($item);

        $this->assertCount(1, $this->pdo->queries);
        $this->assertStringStartsWith('INSERT INTO order_items', $this->pdo->queries[0]);

        $persisted = $this->itemRepo->findById('item-upsert-1');
        $this->assertNotNull($persisted);
        $this->assertSame('Renamed Name', $persisted->name);
        $this->assertCount(1, $this->itemRepo->findByOrderId('ord-upsert-1'));
    }

    public function test_item_save_many_persists_all_rows_in_one_statement(): void
    {
        $items = [
            $this->makeItem('item-bulk-1', 'Product 1'),
            $this->makeItem('item-bulk-2', 'Product 2'),
            $this->makeItem('item-bulk-3', 'Product 3'),
        ];

        $this->itemRepo->saveMany($items);

        $this->assertCount(1, $this->pdo->queries);
        $this->assertStringStartsWith('INSERT INTO order_items', $this->pdo->queries[0]);
        $this->assertCount(3, $this->itemRepo->findByOrderId('ord-upsert-1'));

        // saveMany kedua wajib meng-upsert (bukan gagal duplicate key / ganda).
        $items[1]->name = 'Product 2 updated';
        $items[1]->updatedAt = new \DateTimeImmutable;
        $this->pdo->resetQueryLog();
        $this->itemRepo->saveMany($items);

        $this->assertCount(1, $this->pdo->queries);

        $persisted = $this->itemRepo->findByOrderId('ord-upsert-1');
        $this->assertCount(3, $persisted);
        $names = array_map(static fn (OrderItem $item): string => $item->name, $persisted);
        $this->assertContains('Product 2 updated', $names);
    }

    public function test_item_save_many_with_empty_array_is_noop(): void
    {
        $this->itemRepo->saveMany([]);

        $this->assertCount(0, $this->pdo->queries);
        $this->assertCount(0, $this->itemRepo->findByOrderId('ord-upsert-1'));
    }

    public function test_order_update_path_uses_single_update_without_select(): void
    {
        $order = $this->orderRepo->findById('ord-upsert-1');
        $this->assertNotNull($order);

        $this->pdo->resetQueryLog();
        $order->note = 'touched';
        $this->orderRepo->save($order);

        $this->assertCount(1, $this->pdo->queries);
        $this->assertStringStartsWith('UPDATE orders', $this->pdo->queries[0]);
        $this->assertSame(2, $order->version);

        $persisted = $this->orderRepo->findById('ord-upsert-1');
        $this->assertNotNull($persisted);
        $this->assertSame('touched', $persisted->note);
        $this->assertSame(2, $persisted->version);
    }

    public function test_order_insert_persists_row_with_initial_version(): void
    {
        $this->orderRepo->save(new Order(
            id: 'ord-upsert-2',
            orderNumber: 'ORD-UPSERT-002',
            customerType: 'user',
            customerId: 'user-upsert-2'
        ));

        $this->assertSame(
            [],
            array_values(array_filter(
                $this->pdo->queries,
                static fn (string $query): bool => str_starts_with($query, 'SELECT')
            )),
            'Insert order tidak boleh diawali SELECT.'
        );

        $persisted = $this->orderRepo->findById('ord-upsert-2');
        $this->assertNotNull($persisted);
        $this->assertSame(1, $persisted->version);
        $this->assertSame('ORD-UPSERT-002', $persisted->orderNumber);
    }

    public function test_create_order_with_items_uses_single_bulk_insert(): void
    {
        $orderService = new \Ttpryg\OrderEngine\Services\OrderService(
            $this->orderRepo,
            $this->itemRepo,
            new \Ttpryg\OrderEngine\Repositories\PdoOrderAddressRepository($this->pdo),
            new \Ttpryg\OrderEngine\Repositories\PdoOrderHistoryRepository($this->pdo),
            pdo: $this->pdo
        );

        $this->pdo->resetQueryLog();
        $orderService->createOrder(
            id: 'ord-upsert-3',
            customerType: 'user',
            customerId: 'user-upsert-3',
            items: [
                $this->makeItem('item-service-1', 'A', 'ord-upsert-3'),
                $this->makeItem('item-service-2', 'B', 'ord-upsert-3'),
                $this->makeItem('item-service-3', 'C', 'ord-upsert-3'),
            ],
            orderNumber: 'ORD-UPSERT-003'
        );

        $itemInserts = array_values(array_filter(
            $this->pdo->queries,
            static fn (string $query): bool => str_starts_with($query, 'INSERT INTO order_items')
        ));
        $this->assertCount(1, $itemInserts, 'Tiga item harus ditulis dalam satu bulk INSERT.');
        $this->assertCount(3, $this->itemRepo->findByOrderId('ord-upsert-3'));
    }

    private function makeItem(string $id, string $name, string $orderId = 'ord-upsert-1'): OrderItem
    {
        return new OrderItem(
            id: $id,
            orderId: $orderId,
            productId: 'prod-upsert',
            name: $name,
            quantity: 1,
            unitPrice: 15000
        );
    }
}
