<?php

use App\Infrastructure\Providers\Blockchain\TronGridBlockchainGateway;

return [
    'driver' => env('PAYMENT_PROVIDER_DRIVER', 'mock'),
    'mock_mode' => env('PAYMENT_PROVIDER_MOCK_MODE', 'PENDING'),
    'mock_webhook_secret' => env('PAYMENT_PROVIDER_MOCK_WEBHOOK_SECRET', 'local-mock-payment-secret'),
    'unknown_after_minutes' => (int) env('PAYMENT_UNKNOWN_AFTER_MINUTES', 5),
    'recovery_batch_size' => (int) env('PAYMENT_RECOVERY_BATCH_SIZE', 100),
    'initiation_lease_seconds' => (int) env('PAYMENT_INITIATION_LEASE_SECONDS', 120),
    'initiation_recovery_after_seconds' => (int) env('PAYMENT_INITIATION_RECOVERY_AFTER_SECONDS', 30),
    'topup_rate_limit_per_minute' => (int) env('PAYMENT_TOPUP_RATE_LIMIT_PER_MINUTE', 10),
    'webhook_max_bytes' => (int) env('PAYMENT_WEBHOOK_MAX_BYTES', 65536),
    'checkout_hosts' => env('PAYMENT_CHECKOUT_HOSTS', ''),
    'trc20_deposit_address' => env('TRON_USDT_DEPOSIT_ADDRESS'),
    'trc20_token_contract' => TronGridBlockchainGateway::TOKEN,
    'trc20_validity_minutes' => (int) env('TRON_TOPUP_VALIDITY_MINUTES', 30),
    'trc20_required_confirmations' => (int) env('TRON_TOPUP_REQUIRED_CONFIRMATIONS', 20),
    'trc20_mock_incoming_transfers' => [],
];
