<?php

return [
    // Server storage is the default until OSS is explicitly selected again.
    'storage' => env('IMAGE_STORAGE_DRIVER', 'server'),

    // Explicitly published public logos. New branding keys do not reuse an old logo.
    'public_branding_urls' => [
        'tenant-branding/01a09996-8c36-7288-bf98-889103088ba6/qW8pi1wjFbuMOj2Nhr2kAIskMgT6KycmymGMTzO1.png' =>
            'https://zb33333.com/assets/branding/0061b65cbcbd00c086abaf0a3e15cf756fe67f95c23e871fc5ab9326d843c802/logo.png',
    ],
];
