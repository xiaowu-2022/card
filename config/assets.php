<?php

return [
    // Explicit deployment allowlist. Admin-entered endpoints cannot target arbitrary hosts.
    'rpc_allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('ASSET_RPC_ALLOWED_HOSTS', ''))))),
    'scan_batch_blocks' => 10,
];
