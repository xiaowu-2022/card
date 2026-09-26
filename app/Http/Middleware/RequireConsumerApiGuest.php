<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireConsumerApiGuest
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->attributes->get('consumer_user') !== null, 409);

        return $next($request);
    }
}
