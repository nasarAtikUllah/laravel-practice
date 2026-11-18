<?php

return [
    'default' => env('PAYMENT_GATEWAY', 'sslcommerz'),

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
        ],
    ],
];
