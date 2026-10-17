<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ttpryg\OrderEngine\Enums\OrderStatus;
use Ttpryg\OrderEngine\Enums\PaymentStatus;

class PaymentStatusTest extends TestCase
{
    public function test_derives_from_order_status(): void
    {
        $this->assertSame(PaymentStatus::UNPAID, PaymentStatus::fromOrderStatus(OrderStatus::PENDING));
        $this->assertSame(PaymentStatus::UNPAID, PaymentStatus::fromOrderStatus(OrderStatus::CANCELLED));
        $this->assertSame(PaymentStatus::PAID, PaymentStatus::fromOrderStatus(OrderStatus::CONFIRMED));
        $this->assertSame(PaymentStatus::PAID, PaymentStatus::fromOrderStatus(OrderStatus::PROCESSING));
        $this->assertSame(PaymentStatus::PAID, PaymentStatus::fromOrderStatus(OrderStatus::COMPLETED));
        $this->assertSame(PaymentStatus::REFUNDED, PaymentStatus::fromOrderStatus(OrderStatus::REFUNDED));
    }

    public function test_every_order_status_is_represented_by_its_payment_status(): void
    {
        foreach (OrderStatus::cases() as $status) {
            $paymentStatus = PaymentStatus::fromOrderStatus($status);
            $this->assertContains($status, $paymentStatus->orderStatuses());
        }
    }

    public function test_order_statuses_are_disjoint_and_exhaustive(): void
    {
        $all = array_merge(
            PaymentStatus::UNPAID->orderStatuses(),
            PaymentStatus::PAID->orderStatuses(),
            PaymentStatus::REFUNDED->orderStatuses()
        );

        $this->assertSame(count(OrderStatus::cases()), count($all));
        $this->assertEqualsCanonicalizing(OrderStatus::cases(), $all);
    }
}
