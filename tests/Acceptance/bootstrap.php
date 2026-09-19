<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// CLI/browser acceptance bootstrap: no routes or production hooks are installed.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (PHP_SAPI === 'cli') {
    set_exception_handler(function (Throwable $error): never {
        fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL);
        exit(1);
    });
}
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'card_ui_test') {
    throw new RuntimeException('Acceptance requires testing and card_ui_test.');
}
Http::preventStrayRequests();
config(['kyc.data_encryption_key' => 'base64:'.base64_encode(str_repeat('d', 32)), 'kyc.identity_hash_key' => str_repeat('k', 32),
    'withdrawal.address_encryption_key' => 'base64:'.base64_encode(str_repeat('e', 32)), 'withdrawal.address_hash_key' => str_repeat('f', 32),
    'filesystems.disks.private.root' => storage_path('framework/testing/financial-acceptance/private')]);
