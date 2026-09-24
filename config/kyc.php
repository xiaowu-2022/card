<?php

return [
    'data_encryption_key' => env('KYC_DATA_ENCRYPTION_KEY'),
    'identity_hash_key' => env('KYC_IDENTITY_HASH_KEY'),
    'ocr_driver' => env('KYC_OCR_DRIVER', 'aliyun'),
    'aliyun' => ['access_key_id' => env('ALIYUN_OCR_ACCESS_KEY_ID'), 'access_key_secret' => env('ALIYUN_OCR_ACCESS_KEY_SECRET')],
    'mock_ocr_mode' => env('KYC_MOCK_OCR_MODE', 'SUCCESS'),
    'document_disk' => env('KYC_DOCUMENT_DISK', 'private'),
    'document_max_mb' => (int) env('KYC_DOCUMENT_MAX_MB', 10),
    'document_access_ttl_seconds' => (int) env('KYC_DOCUMENT_ACCESS_TTL_SECONDS', 300),
    'document_access_rate_limit_per_minute' => (int) env('KYC_DOCUMENT_ACCESS_RATE_LIMIT_PER_MINUTE', 30),
    'admin_recent_auth_ttl_seconds' => (int) env('KYC_ADMIN_RECENT_AUTH_TTL_SECONDS', 900),
];
