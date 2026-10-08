<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\TestCase;

final class OperationRateLimitsTest extends TestCase
{
    public function test_domain_discovery_allows_sixty_requests_and_still_enforces_the_limit(): void
    {
        Cache::flush();
        $middleware = app(ThrottleRequests::class);
        $request = Request::create('https://rate-limit.example/api/mobile/v1/domains');
        for ($i = 0; $i < 60; $i++) {
            $response = $middleware->handle($request, fn () => response('ok'), 'consumer-domains');
            $this->assertSame(200, $response->getStatusCode());
        }
        // Another company host must not consume this host's discovery allowance.
        $other = Request::create('https://another-rate-limit.example/api/mobile/v1/domains');
        $this->assertSame(200, $middleware->handle($other, fn () => response('ok'), 'consumer-domains')->getStatusCode());
        $this->expectException(TooManyRequestsHttpException::class);
        $middleware->handle($request, fn () => response('ok'), 'consumer-domains');
    }
}
