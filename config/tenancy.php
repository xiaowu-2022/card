<?php

return [
    'root_domain' => env('PLATFORM_ROOT_DOMAIN', 'localhost'),
    'platform_admin_host' => env('PLATFORM_ADMIN_HOST', 'admin.localhost'),
    'invitation_expiration_hours' => (int) env('ADMIN_INVITATION_EXPIRATION_HOURS', 72),
    'reserved_slugs' => ['admin', 'api', 'www', 'platform', 'support'],
    'supported_locales' => ['en', 'zh-CN', 'ms', 'es'],
    // One wallet unit; PhotonPay's USD denomination is settled at fixed 1:1.
    'supported_assets' => ['USDT'],
    'local_verified_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('LOCAL_VERIFIED_DOMAINS', 'cards.example.test'))))),
];
