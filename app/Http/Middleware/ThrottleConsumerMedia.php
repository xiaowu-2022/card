<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

final readonly class ThrottleConsumerMedia
{
    public function __construct(private ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next, string $limiter): Response
    {
        // Do not extend ThrottleRequests: its framework priority moves it ahead
        // of tenant resolution and native token authentication. Delegate only
        // after this route's consumer identity and access checks have run.
        return $this->throttle->handle($request, $next, $limiter);
    }
}
