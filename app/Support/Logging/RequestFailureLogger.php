<?php

namespace App\Support\Logging;

use App\Support\Errors\DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class RequestFailureLogger
{
    public function record(Request $request, int $status, ?\Throwable $exception = null, array $fields = []): void
    {
        if ($request->attributes->get('failure_logged')) {
            return;
        }
        $code = match (true) {
            $exception instanceof DomainException => $exception->errorCode,
            $exception instanceof ValidationException => 'VALIDATION_FAILED',
            $exception instanceof AuthenticationException => 'AUTHENTICATION_REQUIRED',
            $exception instanceof AuthorizationException => 'AUTHORIZATION_DENIED',
            default => 'HTTP_'.$status,
        };
        if ($exception instanceof ValidationException) {
            $fields = array_keys($exception->errors());
        }
        $code = preg_match('/^[A-Z][A-Z0-9_]{0,100}$/D', $code) ? $code : 'HTTP_'.$status;
        $route = $request->route();
        $context = ['request_id' => $request->attributes->get('request_id'), 'method' => $request->method(),
            'host' => $request->getHost(),
            'path' => $this->safePath($request->getPathInfo()),
            'path_hash' => hash('sha256', $request->getPathInfo()),
            'path_encrypted' => $this->encryptedPath($request->getPathInfo()),
            'referer_path' => $this->safePath((string) parse_url((string) $request->header('Referer', ''), PHP_URL_PATH)),
            'fetch_destination' => $this->knownHeader($request, 'Sec-Fetch-Dest', ['document', 'empty', 'image', 'script', 'style', 'font', 'iframe', 'manifest', 'worker']),
            'fetch_mode' => $this->knownHeader($request, 'Sec-Fetch-Mode', ['navigate', 'cors', 'no-cors', 'same-origin', 'websocket']),
            'expects_json' => $request->expectsJson(),
            'is_inertia' => $request->header('X-Inertia') === 'true',
            'client_page' => $this->safePath((string) parse_url(substr((string) $request->header('X-Consumer-Page', ''), 0, 1024), PHP_URL_PATH)),
            'route' => is_object($route) ? $route->getName() : null,
            'route_template' => is_object($route) ? $route->uri() : null,
            'http_status' => $status, 'error_code' => $code,
            'exception_class' => $exception ? get_class($exception) : null,
            'validation_fields' => array_slice(array_values(array_filter($fields,
                fn ($field) => is_string($field) && preg_match('/^[a-zA-Z0-9_.-]{1,120}$/D', $field))), 0, 100)];
        if ($exception instanceof ValidationException && $exception->validator) {
            $rules = [];
            foreach ($exception->validator->failed() as $field => $failed) {
                if (in_array($field, $context['validation_fields'], true)) {
                    $rules[$field] = array_values(array_filter(array_keys($failed),
                        fn ($rule) => preg_match('/^[a-zA-Z]{1,80}$/D', $rule)));
                }
            }
            $context['validation_rules'] = $rules;
        }
        foreach (['tenant', 'domain'] as $parameter) {
            $value = is_object($route) ? $route->parameter($parameter) : null;
            $value = $value instanceof \Illuminate\Database\Eloquent\Model ? $value->getKey() : $value;
            if (is_string($value) && \Illuminate\Support\Str::isUuid($value)) {
                $context[$parameter.'_id'] = $value;
            }
        }
        // Whitelist UUID selections only; never log request bodies, messages or exception details.
        if (is_object($route) && str_contains((string) $route->getName(), 'domain')) {
            foreach (['domain_ids', 'original_ids'] as $field) {
                $ids = $request->input($field);
                if (is_array($ids)) {
                    $context[$field] = array_slice(array_values(array_filter($ids,
                        fn ($id) => is_string($id) && \Illuminate\Support\Str::isUuid($id))), 0, 100);
                }
            }
        }
        try {
            foreach (['platform_admin', 'tenant_admin', 'tenant_user'] as $guard) {
                $actor = $request->user($guard);
                if ($actor) {
                    $context['actor_id'] = $actor->getAuthIdentifier();
                    $context['actor_guard'] = $guard;
                    break;
                }
            }
        } catch (\Throwable) {
            // Authentication itself can be the failing operation.
        }
        try {
            Log::log($status >= 500 ? 'error' : 'warning', 'HTTP request failed', $context);
            $request->attributes->set('failure_logged', true);
        } catch (\Throwable) {
            // A logging failure must not change the original response.
        }
    }

    private function encryptedPath(string $path): ?string
    {
        try {
            return \Illuminate\Support\Facades\Crypt::encryptString(substr($path, 0, 8192));
        } catch (\Throwable) {
            return null;
        }
    }

    private function knownHeader(Request $request, string $header, array $allowed): ?string
    {
        $value = $request->header($header);
        return in_array($value, $allowed, true) ? $value : null;
    }

    /** Preserve routing vocabulary while excluding arbitrary identifiers and credentials. */
    private function safePath(string $path): ?string
    {
        if ($path === '') return null;
        $known = ['api', 'v1', 'v2', 'mobile', 'client', 'h5', 'assets', 'static', 'storage', 'favicon.ico', 'robots.txt', 'pages', 'index', '.well-known', 'appspecific', 'com.chrome.devtools.json'];
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            foreach (explode('/', $route->uri()) as $part) {
                if ($part !== '' && ! str_contains($part, '{')) $known[] = $part;
            }
        }
        $segments = explode('/', substr($path, 0, 2048));
        return implode('/', array_map(function ($part) use ($known): string {
            if ($part === '' || in_array($part, $known, true)) return $part;
            if (\Illuminate\Support\Str::isUuid($part)) return ':uuid';
            if (ctype_digit($part)) return ':number';
            return ':redacted';
        }, array_slice($segments, 0, 32)));
    }

    public function exception(Request $request, \Throwable $exception): void
    {
        $status = match (true) {
            $exception instanceof DomainException => $exception->httpStatus,
            $exception instanceof ValidationException => $exception->status,
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AuthorizationException => $exception->status() ?? 403,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            $exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException => 404,
            $exception instanceof \Illuminate\Session\TokenMismatchException => 419,
            default => 500,
        };
        $this->record($request, $status, $exception);
    }
}
