<?php

return [
    'driver' => env('CARD_PROVIDER_DRIVER', 'mock'),
    'mock_mode' => env('MOCK_CARD_PROVIDER_MODE', 'SUCCESS'),
    'mock_cardholder_mode' => env('MOCK_CARDHOLDER_MODE', 'READY'),
    'photonpay' => [
        'base_url' => env('PHOTONPAY_BASE_URL', 'https://x-api.photonpay.com'),
        'app_id' => env('PHOTONPAY_APP_ID'),
        'app_secret' => env('PHOTONPAY_APP_SECRET'),
        'private_key' => env('PHOTONPAY_PRIVATE_KEY'),
        'account_id_usd' => env('PHOTONPAY_USD_ACCOUNT_ID'),
        'member_id' => env('PHOTONPAY_MEMBER_ID'),
        'matrix_account' => env('PHOTONPAY_MATRIX_ACCOUNT'),
        'timeout_seconds' => (int) env('PHOTONPAY_TIMEOUT_SECONDS', 20),
    ],
];
