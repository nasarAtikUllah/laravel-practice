<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case PendingConfirmation = 'pending_confirmation';
    case Confirmed = 'confirmed';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /** @return array<int, self> the statuses an order may move to from this one */
    public function next(): array
    {
        return match ($this) {
            self::PendingPayment, self::PendingConfirmation => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Delivered],
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    public function isFinal(): bool
    {
        return $this->next() === [];
    }
}
