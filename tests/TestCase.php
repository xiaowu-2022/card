<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Report refreshes are background work, never external calls from test fixtures.
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\RefreshPhotonPayAccountReport::class]);
    }
}
