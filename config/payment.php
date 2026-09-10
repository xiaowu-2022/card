<?php

return [
    'driver' => env('PAYMENT_PROVIDER_DRIVER', 'mock'),
    'mock_mode' => env('PAYMENT_PROVIDER_MOCK_MODE', 'PENDING'),
    'mock_webhook_secret' => env('PAYMENT_PROVIDER_MOCK_WEBHOOK_SECRET', 'local-mock-payment-secret'),
    'unknown_after_minutes' => (int) env('PAYMENT_UNKNOWN_AFTER_MINUTES', 5),
    'recovery_batch_size' => (int) env('PAYMENT_RECOVERY_BATCH_SIZE', 100),
];
