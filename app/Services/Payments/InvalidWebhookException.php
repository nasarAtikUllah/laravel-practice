<?php

namespace App\Services\Payments;

use RuntimeException;

class InvalidWebhookException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 401)
    {
        parent::__construct($message);
    }

    public static function signature(): self
    {
        return new self('The notification signature is not valid.', 401);
    }

    public static function malformed(string $detail): self
    {
        return new self("The notification is not valid: {$detail}", 422);
    }
}
