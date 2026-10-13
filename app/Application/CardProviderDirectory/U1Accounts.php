<?php

namespace App\Application\CardProviderDirectory;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\CardProvider\Exceptions\ProviderRejectedException;
use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Infrastructure\Assets\ExactJson;
use App\Infrastructure\Providers\Card\U1ApiClient;
use App\Infrastructure\Providers\Card\U1NotificationVerifier;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class U1Accounts
{
    public const BASE_URL = 'https://api.uucard.cyou/v1';

    public function __construct(private PhotonPayAccounts $authorization, private U1NotificationVerifier $verifier) {}

    public function save(?string $id, #[\SensitiveParameter] array $input, AdminUser $actor): CardProviderReference
    {
        $this->authorization->authorize($actor);
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'customer_no' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]{1,100}$/D'],
            'api_key' => ['nullable', 'string', 'max:2000'],
            'base_url' => ['required', Rule::in([self::BASE_URL])],
            'version' => [$id ? 'required' : 'prohibited', 'integer', 'min:1'],
            'request_id' => [$id ? 'prohibited' : 'required', 'uuid'],
            'enabled' => ['sometimes', 'boolean'],
            'zero_fees_confirmed' => ['sometimes', 'boolean'],
        ])->validate();
        $data['name'] = trim($data['name']);
        if ($data['name'] === '') {
            throw ValidationException::withMessages(['name' => 'A card provider name is required.']);
        }
        $creationHash = hash_hmac('sha256', json_encode([$data['name'], $data['customer_no'], $data['base_url'], $data['api_key'] ?? '', (bool) ($data['enabled'] ?? false), (bool) ($data['zero_fees_confirmed'] ?? false)], JSON_THROW_ON_ERROR), config('app.key'));

        $creating = $id === null;

        return DB::transaction(function () use ($id, $data, $actor, $creationHash, $creating): CardProviderReference {
            $this->authorization->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['u1-customer:'.$data['customer_no']]);
            if (DB::table('u1_connections')->where('customer_no', $data['customer_no'])->where('reference_id', '!=', $id ?? $data['request_id'])
                ->whereIn('reference_id', CardProviderReference::query()->select('id'))->exists()) {
                throw ValidationException::withMessages(['customer_no' => 'This U1 account is already configured.']);
            }
            if (! $id) {
                $id = $data['request_id'];
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['card-provider-reference:'.$id]);
                $existing = DB::table('u1_connections')->where('reference_id', $id)->first();
                if ($existing) {
                    $reference = CardProviderReference::whereKey($id)->lockForUpdate()->firstOrFail();
                    if ($reference->created_by !== $actor->id || ! hash_equals($existing->creation_hash, $creationHash)) {
                        throw ValidationException::withMessages(['request_id' => 'This request was already used. Start a new creation request.']);
                    }

                    return $reference;
                }
                // Never convert a historical creation request to another provider.
                if (DB::table('platform_card_provider_references')->where('id', $id)->exists()) {
                    throw ValidationException::withMessages(['request_id' => 'This request was already used. Start a new creation request.']);
                }
                $row = app(SaveCardProviderReferenceAction::class)->execute(null, [
                    'name' => $data['name'], 'reference_balance' => '0.00', 'request_id' => $id,
                ], $actor, apiConnection: true);
            } else {
                $row = CardProviderReference::whereKey($id)->lockForUpdate()->firstOrFail();
                if ($row->version !== (int) $data['version']) {
                    throw ValidationException::withMessages(['version' => 'Configuration changed. Refresh before saving.']);
                }
            }
            if ($row->runtime_driver !== 'UNCONFIGURED' || $row->photonpay_issuing_encrypted || $row->photonpay_identity
                || (! DB::table('u1_connections')->where('reference_id', $row->id)->exists() && DB::table('card_products')->where('card_provider_reference_id', $row->id)->exists())) {
                throw ValidationException::withMessages(['provider_type' => 'This connection cannot be converted to U1 notification testing.']);
            }
            $connection = DB::table('u1_connections')->where('reference_id', $row->id)->first();
            if ($connection && $connection->customer_no !== $data['customer_no']) {
                throw ValidationException::withMessages(['customer_no' => 'Customer number is locked. Create a separate connection instead.']);
            }
            $key = $data['api_key'] ?? '';
            if ($key === '' && ! $connection) {
                throw ValidationException::withMessages(['api_key' => 'U1 API key is required.']);
            }
            if ($connection) {
                $values = ['updated_at' => now(), 'enabled' => $data['enabled'] ?? $connection->enabled,

                    'zero_fees_confirmed' => $data['zero_fees_confirmed'] ?? $connection->zero_fees_confirmed];
                if ($key !== '') {
                    $values['api_key_encrypted'] = Crypt::encryptString($key);
                    $values += ['check_status' => 'UNCHECKED', 'account_report' => null, 'group_catalog' => null, 'checked_at' => null];
                }
                DB::table('u1_connections')->where('id', $connection->id)->update($values);
                $row->forceFill(['name' => $data['name'], 'version' => $row->version + 1, 'updated_by' => $actor->id])->save();
            } else {
                DB::table('u1_connections')->insert([
                    'id' => (string) Str::uuid(), 'reference_id' => $row->id, 'customer_no' => $data['customer_no'],
                    'base_url' => self::BASE_URL, 'api_key_encrypted' => Crypt::encryptString($key),
                    'creation_hash' => $creationHash, 'created_at' => now(), 'updated_at' => now(),
                    'enabled' => $data['enabled'] ?? false,
                    'zero_fees_confirmed' => $data['zero_fees_confirmed'] ?? false,
                ]);
                if (! $creating) {
                    $row->forceFill(['name' => $data['name'], 'version' => $row->version + 1, 'updated_by' => $actor->id])->save();
                }
            }
            app(AuditLogger::class)->record(null, 'ADMIN', $actor->id, 'U1_NOTIFICATION_CONNECTION_SAVED', 'card_provider_reference', $row->id, null, [
                'provider' => 'U1', 'credentials_replaced' => $key !== '', 'version' => $row->version,
            ]);

            return $row;
        });
    }

    public function check(string $reference, AdminUser $actor): void
    {
        $this->authorization->authorize($actor);
        $row = CardProviderReference::findOrFail($reference);
        $connection = $this->connection($reference);
        $client = new U1ApiClient($connection->customer_no, Crypt::decryptString($connection->api_key_encrypted), $connection->base_url);
        $ok = false;
        $groups = null;
        $report = null;
        try {
            $account = $client->account();
            $catalog = $client->groups();
            if ($account['resultCode'] !== '0000' || $catalog['resultCode'] !== '0000'
                || ! is_array($account['data']['amount'] ?? null) || ! is_array($catalog['data']['group'] ?? null)) {
                throw new \RuntimeException;
            }
            $groups = [];
            foreach ($catalog['data']['group'] as $group) {
                if (! is_array($group) || ! is_string($group['groupNo'] ?? null) || ! preg_match('/^[a-zA-Z0-9._-]{4,64}$/D', $group['groupNo'])
                    || ! is_string($group['bin'] ?? null) || ! preg_match('/^\d{6,10}$/D', $group['bin'])
                    || ! in_array($group['model'] ?? null, ['SHARE', 'SINGLE'], true) || ! is_string($group['currency'] ?? null)) {
                    throw new \RuntimeException;
                }
                $groups[] = array_intersect_key($group, array_flip(['groupNo', 'bin', 'currency', 'model']));
            }
            if (count(array_unique(array_column($groups, 'groupNo'))) !== count($groups)) {
                throw new \RuntimeException;
            }
            $usd = array_values(array_filter($account['data']['amount'], fn ($amount) => is_array($amount) && ($amount['currency'] ?? null) === 'USD'));
            $balance = count($usd) === 1 ? $this->reportAmount($usd[0]['freeAmt'] ?? null) : null;
            $count = $account['data']['cardCount'] ?? null;
            if ($count !== null && (! is_string($count) || ! preg_match('/^\d{1,12}$/D', $count))) {
                throw new \RuntimeException;
            }
            $report = ['balance' => $balance, 'asset' => 'USD', 'issuedCardCount' => $count, 'queriedAt' => now()->toIso8601String()];
            $ok = true;
        } catch (\Throwable) {
            $ok = false;
        }
        DB::transaction(function () use ($row, $actor, $connection, $ok, $groups, $report): void {
            $this->authorization->authorize($actor);
            $current = CardProviderReference::whereKey($row->id)->lockForUpdate()->firstOrFail();
            if ($current->version !== $row->version) {
                throw ValidationException::withMessages(['version' => 'Configuration changed. Refresh before saving.']);
            }
            DB::table('u1_connections')->where('id', $connection->id)->update(['check_status' => $ok ? 'VERIFIED' : 'FAILED',
                'checked_at' => now(), 'group_catalog' => $ok ? json_encode($groups, JSON_THROW_ON_ERROR) : null,
                'account_report' => $ok ? json_encode($report, JSON_THROW_ON_ERROR) : null]);
            foreach (DB::table('card_products')->where('card_provider_reference_id', $row->id)->lockForUpdate()->get() as $product) {
                $group = collect($groups ?? [])->firstWhere('groupNo', $product->provider_product_ref);
                DB::table('card_products')->where('id', $product->id)->update(['supported_form_factors' => json_encode($ok && ($group['currency'] ?? null) === 'USD' ? ['virtual_card'] : []), 'form_factors_synced_at' => now()]);
            }
            app(AuditLogger::class)->record(null, 'ADMIN', $actor->id, 'U1_ACCOUNT_CHECKED', 'card_provider_reference', $row->id, null, ['status' => $ok ? 'VERIFIED' : 'FAILED']);
        });
        if (! $ok) {
            throw ValidationException::withMessages(['connection' => 'U1 account or card groups could not be verified.']);
        }
    }

    private function reportAmount(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{1,12}(?:\.\d{1,8})?$/D', $value)) {
            return null;
        }

        return (string) BigDecimal::of($value)->toScale(8);
    }

    public function publicConfiguration(string $reference): ?array
    {
        $connection = DB::table('u1_connections')->where('reference_id', $reference)->first();
        if (! $connection) {
            return null;
        }
        // Use configured application origin, never an untrusted request Host.
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        $port = parse_url((string) config('app.url'), PHP_URL_PORT);
        $origin = $host ? 'https://'.$host.($port ? ':'.$port : '') : null;

        return ['customerNo' => $connection->customer_no, 'baseUrl' => $connection->base_url,
            'callbackUrl' => $origin ? $origin.'/webhooks/u1/'.$connection->id : null,
            'provider' => 'U1', 'enabled' => (bool) $connection->enabled,
            'zeroFeesConfirmed' => (bool) $connection->zero_fees_confirmed,
            'checkStatus' => $connection->check_status, 'checkedAt' => $connection->checked_at,
            'groups' => json_decode($connection->group_catalog ?? '[]', true), 'types' => array_keys(U1NotificationVerifier::FIELDS)];
    }

    private function connection(string $reference): object
    {
        CardProviderReference::findOrFail($reference);

        return DB::table('u1_connections')->where('reference_id', $reference)->firstOrFail();
    }

    public function send(string $reference, array $input, AdminUser $actor): array
    {
        $this->authorization->authorize($actor);
        $data = Validator::make($input, [
            'request_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1'],
            'notification_type' => ['required', Rule::in(array_keys(U1NotificationVerifier::FIELDS))],
        ])->validate();
        [$connection, $created] = DB::transaction(function () use ($reference, $data, $actor): array {
            $this->authorization->authorize($actor);
            $row = CardProviderReference::whereKey($reference)->lockForUpdate()->firstOrFail();
            $connection = $this->connection($reference);
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['u1-test-request:'.$data['request_id']]);
            $old = DB::table('u1_notification_tests')->where('id', $data['request_id'])->first();
            if ($old) {
                abort_unless($old->connection_id === $connection->id && $old->actor_id === $actor->id && $old->notification_type === $data['notification_type'] && $old->connection_version === (int) $data['version'], 409);

                return [$connection, false];
            }
            if ($row->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Configuration changed. Refresh before saving.']);
            }
            DB::table('u1_notification_tests')->insert(['id' => $data['request_id'], 'connection_id' => $connection->id,
                'actor_id' => $actor->id, 'connection_version' => $row->version, 'notification_type' => $data['notification_type'],
                'status' => 'REQUESTING', 'requested_at' => now()]);
            app(AuditLogger::class)->record(null, 'ADMIN', $actor->id, 'U1_NOTIFICATION_TEST_REQUESTED', 'card_provider_reference', $reference, null,
                ['test_request_id' => $data['request_id'], 'notification_type' => $data['notification_type']]);

            return [$connection, true];
        });
        if ($created) {
            $status = 'UNKNOWN';
            $body = null;
            try {
                if ($connection->base_url !== self::BASE_URL) {
                    throw new \RuntimeException('Unsupported U1 origin.');
                }
                $key = Crypt::decryptString($connection->api_key_encrypted);
                $response = (new U1ApiClient($connection->customer_no, $key, $connection->base_url))->testNotification($data['notification_type']);
                $body = json_encode($response, JSON_THROW_ON_ERROR);
                $status = 'ACCEPTED';
            } catch (ProviderRejectedException) {
                $status = 'REJECTED';
            } catch (\Throwable) {
                // Do not log HTTP exceptions: they may contain signatures or provider response secrets.
                $status = 'UNKNOWN';
            }
            DB::table('u1_notification_tests')->where('id', $data['request_id'])->update([
                'status' => $status, 'response_encrypted' => $body === null ? null : Crypt::encryptString($body), 'finished_at' => now(),
            ]);
        }

        return $this->requestStatus($reference, $data['request_id']);
    }

    public function requestStatus(string $reference, string $id): array
    {
        $connection = $this->connection($reference);
        $request = DB::table('u1_notification_tests')->where('connection_id', $connection->id)->where('id', $id)->firstOrFail();
        // notifytest does not document correlation with its generated callback API/card IDs.
        // This is an observation, not a claim that the callback belongs to this request.
        $event = DB::table('u1_notifications')->where('connection_id', $connection->id)
            ->where('notification_type', $request->notification_type)->where('verified', true)
            ->where('last_received_at', '>=', $request->requested_at)->latest('last_received_at')->first();
        $waiting = now()->diffInSeconds($request->requested_at, true) < 30;

        return ['id' => $request->id, 'type' => $request->notification_type, 'status' => $request->status,
            'notificationStatus' => $event ? 'RECEIVED' : ($waiting ? 'WAITING' : 'NOT_RECEIVED'),
            'notificationId' => $event?->id, 'correlation' => 'TYPE_AND_RECEIVE_TIME_ONLY',
            'requestedAt' => $request->requested_at,
            'response' => $request->response_encrypted ? $this->verifier->display(Crypt::decryptString($request->response_encrypted)) : null];
    }

    public function receive(string $connectionId, #[\SensitiveParameter] string $body): array
    {
        abort_if(strlen($body) > 262144, 413);

        return DB::transaction(function () use ($connectionId, $body): array {
            $connection = DB::table('u1_connections')->where('id', $connectionId)->firstOrFail();
            // Locks serialize duplicate deliveries, key replacement, and provider deletion.
            CardProviderReference::includingDeleted()->whereKey($connection->reference_id)->lockForUpdate()->firstOrFail();
            $connection = DB::table('u1_connections')->where('id', $connectionId)->firstOrFail();
            $facts = $this->verifier->verify($body, Crypt::decryptString($connection->api_key_encrypted));
            if (! $facts['verified']) {
                try {
                    $candidate = ExactJson::decode($body);
                } catch (\Throwable) {
                    $candidate = [];
                }
                $intent = is_string($candidate['apiId'] ?? null) ? DB::table('u1_operations')->where('connection_id', $connectionId)->where('api_id', $candidate['apiId'])->first() : null;
                if ($intent?->api_key_snapshot_encrypted && in_array($candidate['type'] ?? null, match ($intent->kind) {
                    'OPEN' => ['openvcc.success', 'openvcc.failed'], 'LOAD' => ['rechargevcc.success', 'rechargevcc.failed'],
                    'RETURN' => ['withdrawvcc.success', 'withdrawvcc.fail'], default => [],
                }, true)) {
                    $facts = $this->verifier->verify($body, Crypt::decryptString($intent->api_key_snapshot_encrypted));
                }
            }
            $decoded = $facts['verified'] ? ExactJson::decode($body) : [];
            $apiId = is_string($decoded['apiId'] ?? null) && preg_match('/^[a-zA-Z0-9._-]{1,120}$/D', $decoded['apiId']) ? $decoded['apiId'] : null;
            $cardId = $decoded['cardNo'] ?? $decoded['cardno'] ?? null;
            $resourceHash = is_string($cardId) ? hash('sha256', $cardId) : null;
            if (! CardProviderReference::whereKey($connection->reference_id)->exists()) {
                $knownIntent = $apiId && DB::table('u1_operations')->where('connection_id', $connectionId)->where('api_id', $apiId)->exists();
                $knownCard = is_string($cardId) && DB::table('user_cards')->where('provider', 'U1')->where('provider_card_id', $cardId)
                    ->whereIn('card_product_id', DB::table('card_products')->where('card_provider_reference_id', $connection->reference_id)->select('id'))->exists();
                abort_unless($facts['verified'] && ($knownIntent || $knownCard), 404);
            }
            $digest = hash('sha256', $body);
            $old = DB::table('u1_notifications')->where('connection_id', $connectionId)->where('body_digest', $digest)->first();
            if ($old) {
                DB::table('u1_notifications')->where('id', $old->id)->update([
                    'delivery_count' => $old->delivery_count + 1, 'last_received_at' => now(),
                    'verified' => $facts['verified'], 'verification_reason' => $facts['reason'],
                ]);
            } else {
                DB::table('u1_notifications')->insert([
                    'id' => $eventId = (string) Str::uuid(), 'connection_id' => $connectionId, 'body_digest' => $digest,
                    'api_id' => $apiId, 'resource_hash' => $resourceHash, 'notification_type' => $facts['type'], 'verified' => $facts['verified'], 'verification_reason' => $facts['reason'],
                    'body_encrypted' => Crypt::encryptString($body), 'delivery_count' => 1,
                    'received_at' => now(), 'last_received_at' => now(),
                ]);
            }

            return $facts + ['id' => $old?->id ?? $eventId];
        });
    }

    public function notifications(string $reference, int $page): array
    {
        $connection = $this->connection($reference);
        $rows = DB::table('u1_notifications')->where('connection_id', $connection->id)
            ->orderByDesc('received_at')->orderBy('id')->paginate(20, ['id', 'notification_type', 'verified', 'verification_reason', 'processing_status', 'delivery_count', 'received_at', 'last_received_at'], 'page', $page);

        return ['items' => $rows->items(), 'page' => $rows->currentPage(), 'pages' => $rows->lastPage(), 'total' => $rows->total()];
    }

    public function recentRequests(string $reference): array
    {
        $connection = $this->connection($reference);

        return DB::table('u1_notification_tests')->where('connection_id', $connection->id)
            ->orderByDesc('requested_at')->orderByDesc('id')->limit(9)->pluck('id')
            ->map(fn ($id) => $this->requestStatus($reference, $id))->all();
    }

    public function notification(string $reference, string $id): array
    {
        $connection = $this->connection($reference);
        $row = DB::table('u1_notifications')->where('connection_id', $connection->id)->where('id', $id)->firstOrFail();
        $data = $this->verifier->display(Crypt::decryptString($row->body_encrypted));
        $fields = ['cardno', 'cardNo', 'apiId', 'tradetime', 'tradeTime', 'status', 'tradeStatus', 'tradetype', 'tradeType',
            'amount', 'tradeAmt', 'tradeAmount', 'currency', 'tradeCurrency', 'billingamount', 'billAmount', 'billingcurrency', 'billCurrency',
            'free', 'freeAmount', 'freecurrency', 'feeAmount', 'feeCurrency', 'merchantinfo', 'merchantName', 'wallet', 'description'];

        return ['id' => $row->id, 'type' => $row->notification_type, 'verified' => (bool) $row->verified,
            'reason' => $row->verification_reason, 'processingStatus' => $row->processing_status, 'deliveries' => $row->delivery_count,
            'receivedAt' => $row->received_at, 'lastReceivedAt' => $row->last_received_at,
            'fields' => array_combine($fields, array_map(fn ($field) => $data[$field] ?? null, $fields)),
            'payload' => $data, 'signedFields' => U1NotificationVerifier::FIELDS[$row->notification_type] ?? [],
            'balanceIndependentlyVerified' => false];
    }
}
