<?php

return [
    // Server storage is the default until OSS is explicitly selected again.
    'storage' => env('IMAGE_STORAGE_DRIVER', 'server'),
];
