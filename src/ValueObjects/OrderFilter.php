<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\ValueObjects;

use DateTimeImmutable;
use Ttpryg\OrderEngine\Enums\OrderStatus;
use Ttpryg\OrderEngine\Enums\PaymentStatus;

/**
 * Filter opsional untuk pencarian/paginasi order. Semua kondisi bersifat AND;
 * properti yang null diabaikan.
 */
final class OrderFilter
{
    public function __construct(
        public readonly ?OrderStatus $status = null,
        public readonly ?DateTimeImmutable $createdFrom = null,
        public readonly ?DateTimeImmutable $createdTo = null,
        public readonly ?PaymentStatus $paymentStatus = null
    ) {}

    public function isEmpty(): bool
    {
        return ! $this->status instanceof \Ttpryg\OrderEngine\Enums\OrderStatus
            && ! $this->createdFrom instanceof \DateTimeImmutable
            && ! $this->createdTo instanceof \DateTimeImmutable
            && ! $this->paymentStatus instanceof \Ttpryg\OrderEngine\Enums\PaymentStatus;
    }
}
