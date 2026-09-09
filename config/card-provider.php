<?php

return [
    'driver' => env('CARD_PROVIDER_DRIVER', 'mock'),
    'mock_mode' => env('MOCK_CARD_PROVIDER_MODE', 'SUCCESS'),
];
