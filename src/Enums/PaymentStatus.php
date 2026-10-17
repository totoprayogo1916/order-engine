<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Enums;

/**
 * Status pembayaran yang TURUNAN dari lifecycle `OrderStatus` — library ini
 * tidak menyimpan kolom pembayaran (zero cross-domain dependencies).
 *
 * - UNPAID   : pending (belum dikonfirmasi) atau cancelled
 * - PAID     : confirmed, processing, completed (pesanan sudah dikonfirmasi)
 * - REFUNDED : refunded
 */
enum PaymentStatus: string
{
    case UNPAID = 'unpaid';

    case PAID = 'paid';

    case REFUNDED = 'refunded';

    public static function fromOrderStatus(OrderStatus $status): self
    {
        return match ($status) {
            OrderStatus::PENDING, OrderStatus::CANCELLED => self::UNPAID,
            OrderStatus::CONFIRMED, OrderStatus::PROCESSING, OrderStatus::COMPLETED => self::PAID,
            OrderStatus::REFUNDED => self::REFUNDED,
        };
    }

    /**
     * Daftar `OrderStatus` yang mewakili status pembayaran ini — dipakai
     * repository untuk memetakan filter ke klausa `status IN (...)`.
     *
     * @return list<OrderStatus>
     */
    public function orderStatuses(): array
    {
        return match ($this) {
            self::UNPAID => [OrderStatus::PENDING, OrderStatus::CANCELLED],
            self::PAID => [OrderStatus::CONFIRMED, OrderStatus::PROCESSING, OrderStatus::COMPLETED],
            self::REFUNDED => [OrderStatus::REFUNDED],
        };
    }
}
