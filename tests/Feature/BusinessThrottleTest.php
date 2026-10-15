<?php

use App\Domain\Tenant\TenantContext;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Http\Middleware\ThrottleBusinessRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

function businessThrottleRequest(string $feature, string $tenantId, string $userId, string $method = 'GET', array $data = []): Request
{
    $tenant = (new Tenant)->forceFill(['id' => $tenantId]);
    app(TenantContext::class)->set($tenant);
    $user = (new User)->forceFill(['id' => $userId, 'tenant_id' => $tenantId]);
    $request = Request::create('/test', $method, $data);
    $request->attributes->set('consumer_user', $user);
    $route = new Route([$method], 'cards/{card}/'.$feature, ['uses' => 'TestController@'.$feature]);
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);
    return $request;
}

it('isolates ordinary counters by function company and identity while allowing normal bursts', function (): void {
    Cache::flush();
    config(['business-throttle.read_per_minute' => 60]);
    $middleware = app(ThrottleBusinessRequests::class);
    $next = fn () => new Response('', 204);
    for ($i = 0; $i < 60; $i++) {
        $middleware->handle(businessThrottleRequest('history', 'company-a', 'user-a'), $next, '20');
    }
    expect(fn () => $middleware->handle(businessThrottleRequest('history', 'company-a', 'user-a'), $next, '20'))
        ->toThrow(TooManyRequestsHttpException::class);
    foreach ([['other', 'company-a', 'user-a'], ['history', 'company-b', 'user-a'], ['history', 'company-a', 'user-b']] as $scope) {
        expect($middleware->handle(businessThrottleRequest(...$scope), $next, '20')->getStatusCode())->toBe(204);
    }
    $this->travel(61)->seconds();
    expect($middleware->handle(businessThrottleRequest('history', 'company-a', 'user-a'), $next, '20')->getStatusCode())->toBe(204);
});

it('retains the shared card password ceiling across functions without counting ordinary reads', function (): void {
    Cache::flush();
    $middleware = app(ThrottleBusinessRequests::class);
    $next = fn () => new Response('', 204);
    for ($i = 0; $i < 20; $i++) {
        $middleware->handle(businessThrottleRequest('operation'.$i, 'a', 'u', 'POST', ['current_password' => 'incorrect']), $next, 'cards');
    }
    expect(fn () => $middleware->handle(businessThrottleRequest('new-operation', 'a', 'u', 'POST', ['current_password' => 'incorrect']), $next, 'cards'))
        ->toThrow(TooManyRequestsHttpException::class);
    expect($middleware->handle(businessThrottleRequest('history', 'a', 'u'), $next, '240')->getStatusCode())->toBe(204);
});

it('keeps security routes on their original middleware and all ordinary routes off shared numeric throttle', function (): void {
    foreach (['user.php', 'consumer-client.php'] as $file) {
        $source = file_get_contents(base_path('routes/'.$file));
        expect($source)->toContain("'throttle:5,1");
    }
    $routes = app('router')->getRoutes();
    $login = collect($routes)->first(fn ($route) => $route->uri() === 'api/v1/login');
    expect($login->gatherMiddleware())->toContain('throttle:20,1,consumer-login:');
    $presence = collect($routes)->first(fn ($route) => $route->uri() === 'api/v1/unread');
    expect($presence->gatherMiddleware())->toContain('business-throttle:240,1');
});

it('does not double count overlapping group and route middleware or split budgets by object IDs', function (): void {
    Cache::flush();
    config(['business-throttle.read_per_minute' => 2]);
    $middleware = app(ThrottleBusinessRequests::class);
    $next = fn () => new Response('', 204);
    $request = businessThrottleRequest('details', 'a', 'u');
    $middleware->handle($request, $next, '2');
    $middleware->handle($request, $next, '1');
    $middleware->handle(businessThrottleRequest('details', 'a', 'u'), $next, '2');
    expect(fn () => $middleware->handle(businessThrottleRequest('details', 'a', 'u'), $next, '2'))
        ->toThrow(TooManyRequestsHttpException::class);
});

it('separates platform administrators and target companies using authorized route bindings', function (): void {
    Cache::flush();
    config(['business-throttle.read_per_minute' => 1]);
    app(TenantContext::class)->clear();
    $middleware = app(ThrottleBusinessRequests::class);
    $next = fn () => new Response('', 204);
    $make = function (string $company, string $admin): Request {
        $request = Request::create('/platform/tenants/'.$company.'/cards', 'GET');
        $route = new Route(['GET'], 'platform/tenants/{tenant}/cards', ['uses' => 'PlatformController@cards']);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        $request->attributes->set('admin_scope_type', 'PLATFORM');
        $actor = (new \App\Domain\Admin\Models\AdminUser)->forceFill(['id' => $admin]);
        $request->setUserResolver(fn ($guard) => $guard === 'platform_admin' ? $actor : null);
        return $request;
    };
    $a = (string) \Illuminate\Support\Str::uuid();
    $b = (string) \Illuminate\Support\Str::uuid();
    $middleware->handle($make($a, 'admin-a'), $next, '1');
    expect(fn () => $middleware->handle($make($a, 'admin-a'), $next, '1'))->toThrow(TooManyRequestsHttpException::class);
    expect($middleware->handle($make($b, 'admin-a'), $next, '1')->getStatusCode())->toBe(204);
    expect($middleware->handle($make($a, 'admin-b'), $next, '1')->getStatusCode())->toBe(204);
});
