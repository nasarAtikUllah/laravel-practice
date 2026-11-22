<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case NotRequired = 'not_required';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';
}
