<?php

namespace App\Services\Payments;

/** What the provider told us about a payment, after we asked the provider ourselves. */
final class WebhookEvent
{
    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    public const OTHER = 'other';

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $eventId,
        public readonly string $type,
        public readonly string $reference,
        public readonly ?string $providerReference,
        public readonly string $amount,
        public readonly string $currency,
        public readonly ?string $reason,
        public readonly array $payload,
        public readonly ?string $validationId = null,
        public readonly ?string $cardType = null,
        public readonly ?int $riskLevel = null,
        public readonly ?string $riskTitle = null,
    ) {}
}
