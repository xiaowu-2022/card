<?php

namespace App\Http\Middleware;

use App\Support\Logging\RequestFailureLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LogFailedRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $status = $response->getStatusCode();
        if ($status >= 400) {
            app(RequestFailureLogger::class)->record($request, $status);
        } elseif ($response->isRedirection() && $request->hasSession()) {
            $errors = $request->session()->get('errors');
            if ($errors instanceof \Illuminate\Support\ViewErrorBag && $errors->any()) {
                app(RequestFailureLogger::class)->record($request, 422, fields: $errors->getBag('default')->keys());
            }
        }

        return $response;
    }
}
