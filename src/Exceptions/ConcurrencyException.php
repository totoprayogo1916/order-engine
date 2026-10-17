<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Exceptions;

use RuntimeException;

class ConcurrencyException extends RuntimeException
{
    public static function forOrder(string $orderId, int $expectedVersion): self
    {
        return new self("Order {$orderId} has been modified by another process (expected version {$expectedVersion}).");
    }
}
