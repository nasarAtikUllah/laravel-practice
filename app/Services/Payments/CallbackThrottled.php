<?php

namespace App\Services\Payments;

use RuntimeException;

class CallbackThrottled extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Too many notifications for this payment.');
    }
}
