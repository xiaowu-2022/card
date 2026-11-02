<?php

use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Illuminate\Support\Env;

it('loads the restored OCR driver selection from deployment configuration', function (?string $driver, string $expected): void {
    $property = new ReflectionProperty(Env::class, 'repository');
    $original = $property->getValue();
    $repository = RepositoryBuilder::createWithNoAdapters()->addAdapter(ArrayAdapter::class)->make();
    $property->setValue(null, $repository);

    try {
        if ($driver !== null) {
            $repository->set('KYC_OCR_DRIVER', $driver);
        }
        $settings = require dirname(__DIR__, 2).'/config/kyc.php';
        expect($settings['ocr_driver'])->toBe($expected)
            ->and($settings)->not->toHaveKey('aliyun');
    } finally {
        $property->setValue(null, $original);
    }
})->with([
    'default' => [null, 'image_url'],
    'explicit image URL' => ['image_url', 'image_url'],
    'retired Aliyun value' => ['aliyun', 'image_url'],
    'retired OCR Space value' => ['ocr_space', 'image_url'],
    'explicit isolated mock' => ['mock', 'mock'],
    'unknown remains unavailable' => ['unknown', 'unknown'],
    'explicit unavailable' => ['unavailable', 'unavailable'],
]);
