<?php

return [
    // How long a pay-now order holds a coupon use. Follows the stock hold time by default.
    'reservation_minutes' => (int) env('COUPON_RESERVATION_MINUTES', env('INVENTORY_RESERVATION_MINUTES', 15)),

    // Quote requests allowed per user per minute.
    'quote_attempts_per_minute' => (int) env('COUPON_QUOTE_LIMIT', 20),
];
