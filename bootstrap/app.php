<?php

use App\Http\Middleware\EnsureTenantSurfaceAvailable;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequestIdMiddleware;
use App\Http\Middleware\ResolveTenantFromHost;
use App\Support\Errors\DomainException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        using: function (): void {
            Route::middleware('web')->group(base_path('routes/public.php'));
            Route::middleware(['web', 'tenant', 'inertia'])->group(base_path('routes/user.php'));
            Route::middleware(['web', 'tenant', 'inertia'])->group(base_path('routes/admin.php'));
            Route::middleware(['web', 'inertia'])->domain((string) config('tenancy.platform_admin_host'))->group(base_path('routes/platform.php'));
            Route::middleware('api')->prefix('webhooks')->group(base_path('routes/webhooks.php'));
        },
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(RequestIdMiddleware::class);
        $middleware->alias([
            'inertia' => HandleInertiaRequests::class,
            'tenant' => ResolveTenantFromHost::class,
            'tenant.surface' => EnsureTenantSurfaceAvailable::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (DomainException $exception, Request $request): ?Response {
            if (! ($request->expectsJson() || $request->is('api/*'))) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                    'request_id' => $request->attributes->get('request_id'),
                    'details' => $exception->details,
                ],
            ], $exception->httpStatus);
        });
    })->create();
