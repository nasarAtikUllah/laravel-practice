<?php

return [
    // How long a pay-now order holds stock while waiting for payment.
    'reservation_minutes' => (int) env('INVENTORY_RESERVATION_MINUTES', 15),

    // Unconfirmed cash-on-delivery orders are cancelled after this many hours.
    'cod_auto_cancel_hours' => (int) env('INVENTORY_COD_AUTO_CANCEL_HOURS', 24),
];
