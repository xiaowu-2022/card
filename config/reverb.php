<?php

return [
    'default' => 'reverb',
    'servers' => ['reverb' => [
        'host' => env('REVERB_SERVER_HOST', '127.0.0.1'),
        'port' => env('REVERB_SERVER_PORT', 8080),
        'path' => '',
        'hostname' => env('REVERB_HOST'),
        'options' => ['tls' => []],
        'max_request_size' => 10000,
        'scaling' => ['enabled' => false],
        'pulse_ingest_interval' => 15,
        'telescope_ingest_interval' => 15,
    ]],
    'apps' => ['provider' => 'config', 'apps' => [[
        'key' => env('REVERB_APP_KEY'),
        'secret' => env('REVERB_APP_SECRET'),
        'app_id' => env('REVERB_APP_ID'),
        'options' => ['host' => env('REVERB_HOST', '127.0.0.1'), 'port' => env('REVERB_PORT', 8080), 'scheme' => env('REVERB_SCHEME', 'http'), 'useTLS' => env('REVERB_SCHEME', 'http') === 'https'],
        // Origin checks are performed by CompanySupportSocketServer against current domain ownership.
        // The package's static list is not used; arbitrary origins remain rejected.
        'allowed_origins' => [],
        'ping_interval' => 20,
        'activity_timeout' => 30,
        'max_connections' => env('REVERB_APP_MAX_CONNECTIONS', 10000),
        'max_message_size' => 10000,
        'accept_client_events_from' => 'none',
        'rate_limiting' => ['enabled' => true, 'max_attempts' => 120, 'decay_seconds' => 60, 'terminate_on_limit' => true],
    ]]],
];
