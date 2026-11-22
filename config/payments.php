<?php

return [
    'default' => env('PAYMENT_GATEWAY', 'sslcommerz'),
    'return_url' => env('PAYMENT_RETURN_URL', rtrim((string) env('APP_URL'), '/').'/shop'),
    'frontend_order_url' => env('PAYMENT_FRONTEND_ORDER_URL'),
    'callback_requests_per_minute' => (int) env('PAYMENT_CALLBACK_LIMIT', 120),
    'callback_attempts_per_reference' => (int) env('PAYMENT_CALLBACK_PER_REFERENCE', 10),

    // A customer can start at most this many payments for one order.
    'max_attempts' => (int) env('PAYMENT_MAX_ATTEMPTS', 3),

    // The limits of one online payment, in BDT. Check them for your SSLCommerz account.
    'min_amount' => (float) env('PAYMENT_MIN_AMOUNT', 10),
    'max_amount' => (float) env('PAYMENT_MAX_AMOUNT', 500000),

    'gateways' => [
        'sslcommerz' => [
            'store_id' => env('SSLCOMMERZ_STORE_ID'),
            'store_password' => env('SSLCOMMERZ_STORE_PASSWORD'),
            'sandbox' => (bool) env('SSLCOMMERZ_SANDBOX', true),
            // The public address of this API. SSLCommerz calls it back here.
            'callback_base' => env('SSLCOMMERZ_CALLBACK_BASE', env('APP_URL')),
            'timeout' => (int) env('SSLCOMMERZ_TIMEOUT', 15),
            // SSLCommerz requires a postcode. We do not collect one.
            'postcode' => env('SSLCOMMERZ_DEFAULT_POSTCODE', '1000'),
            'verify_signature' => (bool) env('SSLCOMMERZ_VERIFY_SIGNATURE', true),

        ],
    ],
];
