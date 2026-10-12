<?php

return [
    'enabled' => env('SUPPORT_REALTIME_ENABLED', false),
    // Leave blank behind the same-origin /app reverse proxy. Local development may expose 8080.
    'public_port' => env('SUPPORT_SOCKET_PORT'),
];
