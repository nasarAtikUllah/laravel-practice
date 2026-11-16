<?php

return [
    'currency' => env('SHOP_CURRENCY', 'BDT'),
    'currency_symbol' => env('SHOP_CURRENCY_SYMBOL', '৳'),

    // Used only when the delivery options are seeded for the first time.
    'free_delivery_min_order' => (float) env('FREE_DELIVERY_MIN_ORDER', 5000),
    'public_requests_per_minute' => (int) env('PUBLIC_RATE_LIMIT', 120),
];
