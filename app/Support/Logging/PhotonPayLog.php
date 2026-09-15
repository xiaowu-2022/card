<?php

namespace App\Support\Logging;

use App\Domain\CardProvider\Exceptions\ProviderAuthenticationException;
use App\Domain\CardProvider\Exceptions\ProviderRateLimitException;
use App\Domain\CardProvider\Exceptions\ProviderRejectedException;
use App\Domain\CardProvider\Exceptions\ProviderUnavailableException;
use App\Domain\CardProvider\Exceptions\ProviderUnknownResultException;
use App\Support\Errors\DomainException;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Allowlisted diagnostics only. Never pass raw bodies, headers or exceptions to a logger. */
final class PhotonPayLog
{
    private array $context;

    private function __construct(array $context)
    {
        $this->context = $context + ['span_id' => (string) Str::uuid()];
    }

    public static function run(string $operation, array $context, Closure $callback): mixed
    {
        $trace = new self($context);
        $started = hrtime(true);
        self::write($operation.'.started', $trace->context);
        try {
            $result = $callback($trace);
            if ($operation === 'management' && is_array($result)) {
                $trace->context['result_summary'] = self::summary($result);
                if (is_array($result['orders'] ?? null)) {
                    $trace->context['result_summary']['record_count'] = count($result['orders']);
                }
                $trace->context['order_state'] = $result['state'] ?? null;
                $trace->context['order_id'] = $result['id'] ?? null;
                $trace->context['server_epoch'] = time();
                $trace->context['app_timezone'] = config('app.timezone');
                $trace->context['db_timezone_config'] = config('database.connections.pgsql.timezone', 'server_default');
                if (is_string($result['expiresAt'] ?? null)) {
                    $deadline = strtotime($result['expiresAt']);
                    if ($deadline !== false) {
                        $trace->context['deadline_epoch'] = $deadline;
                    }
                }
            }
            self::write($operation.'.returned', $trace->context + ['duration_ms' => (int) ((hrtime(true) - $started) / 1000000)]);

            return $result;
        } catch (Throwable $error) {
            self::write($operation.'.failed', $trace->context + [
                'duration_ms' => (int) ((hrtime(true) - $started) / 1000000),
                'failure' => self::failure($error),
            ], true);
            throw $error;
        }
    }

    public function response(Response $response): Response
    {
        $this->context['http_status'] = $response->status();
        try {
            $body = $response->body();
            if (strlen($body) > 2097152) {
                $this->context['response_summary'] = ['data_shape' => 'too_large'];

                return $response;
            }
            if (! json_validate($body, 64)) {
                $this->context['response_summary'] = ['data_shape' => 'invalid_json'];

                return $response;
            }
            // As in the provider decoder, preserve JSON number lexemes before PHP can round them.
            $quoted = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"(*SKIP)(*F)|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/s',
                static fn (array $match): string => '"'.$match[0].'"', $body);
            $decoded = json_decode($quoted ?? '', true, 64, JSON_THROW_ON_ERROR);
            $code = is_array($decoded) ? ($decoded['code'] ?? null) : null;
            if (is_string($code) && preg_match('/^(?:0000|VCC[0-9]{4})$/D', $code)) {
                $this->context['provider_code'] = $code;
            } elseif (is_string($code)) {
                $this->context['provider_code_ref'] = self::reference($code);
            }
            $data = is_array($decoded) ? ($decoded['data'] ?? null) : null;
            $summary = ['data_shape' => is_array($data) ? (array_is_list($data) ? 'list' : 'object') : ($data === null ? 'null' : 'scalar')];
            if (is_array($data) && array_is_list($data)) {
                $summary['record_count'] = count($data);
            } elseif (is_array($data) && self::hasBusinessResponse($this->context['endpoint'] ?? null)) {
                $summary += self::summary($data, true);
                if (is_array($data['cardDetail'] ?? null)) {
                    $summary['cardDetail'] = self::summary($data['cardDetail'], true);
                }
            }
            foreach (['pageIndex', 'pageSize', 'total'] as $key) {
                if (is_string($decoded[$key] ?? null) && preg_match('/^[0-9]{1,9}$/D', $decoded[$key])) {
                    $summary[$key] = $decoded[$key];
                }
            }
            $this->context['response_summary'] = $summary;
        } catch (Throwable) {
            // Diagnostic decoding must never affect the adapter's own validation or result.
            $this->context['response_summary'] = ['data_shape' => 'invalid_json'];
        }

        return $response;
    }

    private static function hasBusinessResponse(mixed $endpoint): bool
    {
        return in_array($endpoint, [
            '/vcc/openApi/v4/getCardDetail', '/vcc/openApi/v4/openCard', '/vcc/openApi/v4/getRequestResult',
            '/vcc/openApi/v4/preRecharge', '/vcc/openApi/v4/recharge', '/vcc/openApi/v4/rechargeReturn',
            '/vcc/openApi/v4/cancelCard', '/vcc/openApi/v4/freezeCard',
        ], true);
    }

    /** Fixed keys, bounded values, no arbitrary nesting or free-form provider text. */
    private static function summary(array $input, bool $provider = false): array
    {
        $safe = [];
        $amounts = $provider
            ? ['cardBalance', 'rechargeAmount', 'arrivalAmount', 'rechargeFee', 'returnAmount', 'returnFeeAmount', 'exchangeRate']
            : ['amount', 'debit', 'arrival', 'fee', 'balance'];
        foreach ($amounts as $key) {
            if (is_string($input[$key] ?? null) && preg_match('/^-?(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,8})?$/D', $input[$key])) {
                $safe[$key] = $input[$key];
            }
        }
        $enums = $provider ? [
            'status' => ['succeed', 'success', 'succeeded', 'failed', 'failure', 'processing', 'pending', 'normal', 'frozen', 'cancelled', 'canceled', 'freeze', 'unfreeze'],
            'cardStatus' => ['normal', 'frozen', 'cancelled', 'canceled', 'inactive', 'active', 'closed'],
            'cardCurrency' => ['USD'], 'rechargeCurrency' => ['USD'],
            'arrivalAmountCurrency' => ['USD'], 'rechargeFeeCurrency' => ['USD'],
        ] : [
            'state' => ['quoted', 'completed', 'declined', 'expired', 'confirming'],
            'kind' => ['load', 'return', 'cancel_return', 'freeze', 'unfreeze', 'cancel', 'holder_update'],
        ];
        foreach ($enums as $key => $values) {
            if (in_array($input[$key] ?? null, $values, true)) {
                $safe[$key] = $input[$key];
            }
        }

        return $safe;
    }

    /** Revalidate summaries at the final logging boundary, including direct write() callers. */
    private static function logSummary(array $input, bool $provider, mixed $endpoint): array
    {
        $safe = ! $provider || self::hasBusinessResponse($endpoint) ? self::summary($input, $provider) : [];
        if ($provider && self::hasBusinessResponse($endpoint) && is_array($input['cardDetail'] ?? null)) {
            $safe['cardDetail'] = self::summary($input['cardDetail'], true);
        }
        if ($provider) {
            if (in_array($input['data_shape'] ?? null, ['object', 'list', 'null', 'scalar', 'invalid_json', 'too_large'], true)) {
                $safe['data_shape'] = $input['data_shape'];
            }
            foreach (['pageIndex', 'pageSize', 'total'] as $key) {
                if (is_string($input[$key] ?? null) && preg_match('/^[0-9]{1,9}$/D', $input[$key])) {
                    $safe[$key] = $input[$key];
                }
            }
        }
        if (is_int($input['record_count'] ?? null) && $input['record_count'] >= 0 && $input['record_count'] <= 1000000) {
            $safe['record_count'] = $input['record_count'];
        }

        return $safe;
    }

    public function failedBecause(Throwable $error): void
    {
        $this->context['failure'] = self::failure($error);
    }

    public function cacheHit(): void
    {
        $this->context['cache_hit'] = true;
    }

    public static function reference(?string $value): ?string
    {
        return $value === null || $value === '' ? null : hash_hmac('sha256', $value, (string) config('app.key'));
    }

    public static function failure(Throwable $error): string
    {
        return match (true) {
            $error instanceof DecryptException => 'decryption',
            $error instanceof LockTimeoutException => 'cache_lock',
            $error instanceof ConnectionException => 'connection',
            $error instanceof ProviderAuthenticationException => 'authentication',
            $error instanceof ProviderRateLimitException => 'rate_limited',
            $error instanceof ProviderRejectedException => 'rejected',
            $error instanceof ProviderUnavailableException => 'unavailable',
            $error instanceof ProviderUnknownResultException => 'unknown_result',
            $error instanceof \JsonException => 'invalid_json',
            $error instanceof DomainException => match ($error->errorCode) {
                'CARD_WEBHOOK_INVALID' => 'invalid_notification',
                'CARD_WEBHOOK_UNAVAILABLE' => 'verification_unavailable',
                'CARD_WEBHOOK_UNSUPPORTED' => 'unsupported_notification',
                'CARD_WEBHOOK_UNMAPPED' => 'unmapped_notification',
                default => 'business_rule',
            },
            default => 'internal',
        };
    }

    public static function write(string $event, array $context = [], bool $warning = false): void
    {
        try {
            $safe = [];
            foreach (['span_id', 'tenant_id', 'resource_id', 'event_id', 'order_id'] as $key) {
                if (is_string($context[$key] ?? null) && Str::isUuid($context[$key])) {
                    $safe[$key] = $context[$key];
                }
            }
            if (app()->bound('request')) {
                $requestId = request()->attributes->get('request_id');
                if (is_string($requestId) && Str::isUuid($requestId)) {
                    $safe['request_id'] = $requestId;
                }
            }
            foreach (['connection_ref', 'provider_request_ref', 'provider_code_ref', 'notification_type_ref'] as $key) {
                if (is_string($context[$key] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $context[$key])) {
                    $safe[$key] = $context[$key];
                }
            }
            foreach (['duration_ms', 'http_status', 'page', 'page_size', 'server_epoch', 'deadline_epoch'] as $key) {
                if (is_int($context[$key] ?? null) && $context[$key] >= 0) {
                    $safe[$key] = $context[$key];
                }
            }
            foreach (['cache_hit', 'duplicate'] as $key) {
                if (is_bool($context[$key] ?? null)) {
                    $safe[$key] = $context[$key];
                }
            }
            foreach (['card_action' => ['quote', 'confirm', 'sync', 'history', 'holder_details', 'reveal', 'refresh', 'return', 'freeze', 'unfreeze', 'cancel', 'holder'],
                'order_state' => ['quoted', 'completed', 'declined', 'expired', 'confirming'],
                'method' => ['GET', 'POST'], 'category' => ['issuing', 'issuing_card', 'issuing_settlement'],
                'failure' => ['connection', 'authentication', 'rate_limited', 'rejected', 'unavailable', 'unknown_result', 'decryption', 'cache_lock', 'invalid_json', 'invalid_notification', 'verification_unavailable', 'unsupported_notification', 'unmapped_notification', 'business_rule', 'internal'],
            ] as $key => $allowed) {
                if (in_array($context[$key] ?? null, $allowed, true)) {
                    $safe[$key] = $context[$key];
                }
            }
            foreach (['app_timezone', 'db_timezone_config'] as $key) {
                if (is_string($context[$key] ?? null) && in_array($context[$key], array_merge(['server_default'], timezone_identifiers_list()), true)) {
                    $safe[$key] = $context[$key];
                }
            }
            if (is_string($context['provider_code'] ?? null) && preg_match('/^(?:0000|VCC[0-9]{4})$/D', $context['provider_code'])) {
                $safe['provider_code'] = $context['provider_code'];
            }
            if (in_array($context['endpoint'] ?? null, [
                '/oauth2/token/accessToken', '/file/apiUpload/issuing_cardholder_identity_certificate',
                '/vcc/openApi/v4/addCardholder', '/vcc/openApi/v4/editCardholder', '/vcc/openApi/v4/getCardBin',
                '/vcc/openApi/v4/getCardDetail', '/vcc/openApi/v4/getCvv', '/vcc/openApi/v4/openCard',
                '/vcc/openApi/v4/getRequestResult', '/vcc/openApi/v4/pagingVccCardholder',
                '/vcc/openApi/v4/pagingVccTradeOrder', '/vcc/openApi/v4/preRecharge', '/vcc/openApi/v4/recharge',
                '/vcc/openApi/v4/rechargeReturn', '/vcc/openApi/v4/cancelCard', '/vcc/openApi/v4/freezeCard',
            ], true)) {
                $safe['endpoint'] = $context['endpoint'];
            }
            foreach (['response_summary', 'result_summary'] as $key) {
                if (is_array($context[$key] ?? null)) {
                    $summary = self::logSummary($context[$key], $key === 'response_summary', $safe['endpoint'] ?? null);
                    if ($summary !== []) {
                        $safe[$key] = $summary;
                    }
                }
            }
            if (! preg_match('/^[a-z_.]{1,64}$/D', $event)) {
                return;
            }
            Log::channel('photonpay')->log($warning ? 'warning' : 'info', 'photonpay.'.$event, $safe);
        } catch (Throwable) {
            // Observability must not change outcomes, cause retries or break webhook acknowledgements.
        }
    }
}
