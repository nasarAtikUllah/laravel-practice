<?php

namespace App\Services\Payments;

use InvalidArgumentException;
use RuntimeException;

class PaymentGatewayManager
{
    public function has(string $name): bool
    {
        return is_array(config("payments.gateways.{$name}"));
    }

    public function driver(?string $name = null): PaymentGateway
    {
        $name ??= (string) config('payments.default', 'sslcommerz');

        if (! $this->has($name)) {
            throw new InvalidArgumentException("The payment gateway [{$name}] is not configured.");
        }

        return match ($name) {
            'sslcommerz' => $this->sslcommerz((array) config('payments.gateways.sslcommerz')),
            default => throw new InvalidArgumentException("The payment gateway [{$name}] has no driver."),
        };
    }

    /** @param array<string, mixed> $config */
    private function sslcommerz(array $config): SslCommerzGateway
    {
        $storeId = (string) ($config['store_id'] ?? '');
        $password = (string) ($config['store_password'] ?? '');

        if ($storeId === '' || $password === '') {
            throw new RuntimeException('The SSLCommerz store id and password are not set.');
        }

        $sandbox = (bool) ($config['sandbox'] ?? true);

        // A live store must never be used by accident on a developer machine.
        if (! $sandbox && ! app()->environment('production')) {
            throw new RuntimeException('A live SSLCommerz store can only be used in production.');
        }

        return new SslCommerzGateway(
            $storeId,
            $password,
            $sandbox,
            rtrim((string) ($config['callback_base'] ?? ''), '/'),
            max(1, (int) ($config['timeout'] ?? 15)),
            (string) ($config['postcode'] ?? '1000'),
        );
    }
}
