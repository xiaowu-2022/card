<?php

use Database\Seeders\LocalPromotionFixtureSeeder;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if ($argc !== 4) {
    throw new RuntimeException('Usage: php scripts/seed-local-promotion.php TENANT_UUID USER_UUID PLATFORM_ACTOR_UUID');
}
$result = app(LocalPromotionFixtureSeeder::class)->run($argv[1], $argv[2], $argv[3], 500, fn ($count) => print ("Prepared {$count}/500 fixture members\n"));
echo json_encode(array_intersect_key($result, array_flip(['totals', 'daily', 'levelName', 'availableCommission', 'myCommission'])), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
