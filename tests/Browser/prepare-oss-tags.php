<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Vite;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$vite = app(Vite::class);
$vite->useHotFile(storage_path('framework/oss-verification-no-hot-file'));
$html = (string) $vite(['resources/js/app.tsx']);
file_put_contents(base_path('output/oss-verification/vite-tags.html'), $html);
echo json_encode(['generated' => true, 'contains_oss_assets' => str_contains($html, '/assets/web/build/assets/')]).PHP_EOL;
