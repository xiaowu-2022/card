<?php

return [
    'asset' => 'USDT',
    'network' => 'TRON',
    'address_encryption_key' => env('WITHDRAWAL_ADDRESS_ENCRYPTION_KEY'),
    'address_hash_key' => env('WITHDRAWAL_ADDRESS_HASH_KEY'),
    'blockchain_driver' => env('BLOCKCHAIN_GATEWAY_DRIVER', 'mock'),
    'mock_verification_mode' => env('BLOCKCHAIN_MOCK_VERIFICATION_MODE', 'CONFIRMED'),
    'minimum_confirmations' => (int) env('TRON_MINIMUM_CONFIRMATIONS', 20),
    'creation_rate_limit_per_minute' => (int) env('WITHDRAWAL_RATE_LIMIT_PER_MINUTE', 10),
];
