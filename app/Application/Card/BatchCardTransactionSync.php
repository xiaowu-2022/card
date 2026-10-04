<?php

namespace App\Application\Card;

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Card\Models\UserCard;
use App\Domain\CardProvider\Exceptions\ProviderRateLimitException;
use App\Domain\CardProvider\Exceptions\ProviderUnavailableException;
use App\Domain\CardProvider\Exceptions\ProviderUnknownResultException;
use App\Domain\CardProvider\ProviderReference;
use App\Jobs\SyncCardTransactionPage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BatchCardTransactionSync
{
    public function allowed(?AdminUser $actor): bool
    {
        $auth = app(AuthorizationService::class);

        return $actor && $actor->status === AdminUserStatus::Active
            && $auth->allows($actor, ScopeType::Platform, null, 'cards.read')
            && $auth->allows($actor, ScopeType::Platform, null, 'card_product.manage');
    }

    public function eligible(UserCard $card): bool
    {
        return $card->provider === 'PHOTONPAY' && trim((string) $card->provider_card_id) !== ''
            && ! ProviderReference::isTest($card->provider_card_id);
    }

    private function accountKey(UserCard $card): string
    {
        $identity = $card->product?->cardProviderReference?->photonpay_identity;
        if (is_array($identity)) {
            ksort($identity);
        }

        return hash('sha256', json_encode($identity ?: 'legacy-photonpay'));
    }

    private function binding(UserCard $card): string
    {
        return hash('sha256', json_encode([$card->tenant_id, $card->user_id, $card->card_product_id,
            $card->product?->card_provider_reference_id, $card->provider, $card->provider_card_id,
            $card->form_factor, $this->accountKey($card)]));
    }

    public function preview(?string $tenant, ?array $selected = null): array
    {
        $cards = $this->cardScope($tenant, $selected);
        $total = 0;
        $eligible = 0;
        foreach ($cards->cursor() as $card) {
            $total++;
            $eligible += (int) $this->eligible($card);
        }

        return ['total' => $total, 'eligible' => $eligible, 'skipped' => $total - $eligible];
    }

    public function create(AdminUser $actor, array $input): string
    {
        abort_unless($this->allowed($actor), 403);

        return DB::transaction(function () use ($actor, $input) {
            // Serialize idempotent submissions for this operator.
            AdminUser::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $selected = $input['card_ids'] ?? null;
            if ($selected !== null) {
                $selected = array_map('strtolower', $selected);
                sort($selected, SORT_STRING);
            }
            $old = DB::table('card_transaction_sync_batches')->where('actor_id', $actor->id)->where('request_id', $input['request_id'])->first();
            if ($old) {
                abort_unless($old->tenant_id === ($input['tenant_id'] ?? null)
                    && $old->date_from === $input['date_from'] && $old->date_to === $input['date_to']
                    && ($old->card_ids === null ? null : json_decode($old->card_ids, true)) === $selected, 409);

                return $old->id;
            }
            $scope = $this->cardScope($input['tenant_id'] ?? null, $selected);
            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('card_transaction_sync_batches')->insert([
                'id' => $id, 'actor_id' => $actor->id, 'request_id' => $input['request_id'],
                'execution_mode' => 'browser', 'card_ids' => $selected === null ? null : json_encode($selected),
                'tenant_id' => $input['tenant_id'] ?? null, 'date_from' => $input['date_from'], 'date_to' => $input['date_to'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
            // One SELECT fixes the complete card set before chunked binding reads.
            $cardIds = $scope->pluck('id');
            foreach ($cardIds->chunk(200) as $ids) {
                $cards = UserCard::with('product.cardProviderReference')->whereIn('id', $ids)->get();
                foreach ($cards as $card) {
                    DB::table('card_transaction_sync_items')->insert([
                        'id' => (string) Str::uuid(), 'batch_id' => $id, 'tenant_id' => $card->tenant_id,
                        'card_id' => $card->id, 'binding_hash' => $this->binding($card), 'account_key' => $this->accountKey($card),
                        'status' => $this->eligible($card) ? 'PENDING' : 'SKIPPED',
                        'error_code' => $this->eligible($card) ? null : 'invalid_provider_binding',
                        'next_attempt_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
            app(AuditLogger::class)->record($input['tenant_id'] ?? null, 'ADMIN', $actor->id,
                'CARD_TRANSACTION_BATCH_CREATED', 'card_transaction_sync_batch', $id, null,
                ['date_from' => $input['date_from'], 'date_to' => $input['date_to']], $input['request_id']);
            DB::afterCommit(fn () => $this->recover($id));

            return $id;
        });
    }

    private function cardScope(?string $tenant, ?array $selected): Builder
    {
        $query = UserCard::when($tenant, fn ($q) => $q->where('tenant_id', $tenant));
        if ($selected !== null) {
            $query->whereIn('id', $selected);
            if ($selected === [] || count($selected) > 500 || $query->count() !== count($selected)) {
                throw ValidationException::withMessages(['card_ids' => 'Selected cards are unavailable in this company.']);
            }
        }

        return $query;
    }

    public function recover(?string $batch = null): void
    {
        DB::table('card_transaction_sync_items')->whereIn('batch_id', DB::table('card_transaction_sync_batches')->where('execution_mode', 'queue')->select('id'))->where('status', 'PENDING')
            ->where('next_attempt_at', '<=', now()->toIso8601String())
            ->when($batch, fn ($q) => $q->where('batch_id', $batch))
            ->orderBy('next_attempt_at')->limit(500)->get(['id'])
            ->each(fn ($item) => SyncCardTransactionPage::dispatch($item->id)->onConnection('database')->onQueue('card-transaction-sync'));
    }

    /** One browser request advances at most one page, using persisted checkpoints. */
    public function advance(AdminUser $actor, string $batch): array
    {
        abort_unless($this->allowed($actor), 403);
        $row = DB::table('card_transaction_sync_batches')->where('actor_id', $actor->id)->where('id', $batch)->first();
        abort_unless($row, 404);
        abort_unless($row->execution_mode === 'browser', 409);
        $pending = DB::table('card_transaction_sync_items')->where('batch_id', $batch)->where('status', 'PENDING');
        $item = (clone $pending)->where('next_attempt_at', '<=', now()->toIso8601String())->orderBy('next_attempt_at')->orderBy('updated_at')->orderBy('id')->value('id');
        if ($item) {
            $this->process($item);
        }
        $next = (clone $pending)->min('next_attempt_at');
        $failed = DB::table('card_transaction_sync_items')->where('batch_id', $batch)->where('status', 'FAILED')->exists();

        return ['status' => $next ? 'RUNNING' : ($failed ? 'PARTIAL_FAILED' : 'COMPLETED'),
            'waitMs' => $next ? max(1100, min(30000, (int) now()->diffInMilliseconds(CarbonImmutable::parse($next), false))) : 0];
    }

    public function retry(AdminUser $actor, string $batch): void
    {
        abort_unless($this->allowed($actor), 403);
        DB::transaction(function () use ($actor, $batch) {
            $row = DB::table('card_transaction_sync_batches')->where('actor_id', $actor->id)->where('id', $batch)->lockForUpdate()->first();
            abort_unless($row, 404);
            // Start failed cards at page one: offset pages may have shifted since failure.
            $count = DB::table('card_transaction_sync_items')->where('batch_id', $batch)->where('status', 'FAILED')->update([
                'status' => 'PENDING', 'next_page' => 1, 'failures' => 0, 'error_code' => null,
                'next_attempt_at' => now(), 'updated_at' => now(),
            ]);
            app(AuditLogger::class)->record($row->tenant_id, 'ADMIN', $actor->id,
                'CARD_TRANSACTION_BATCH_RETRIED', 'card_transaction_sync_batch', $batch, null, ['cards' => $count], $row->request_id);
            DB::afterCommit(fn () => $this->recover($batch));
        });
    }

    /** PostgreSQL session locks cover external reads without holding row locks/transactions. */
    public function process(string $id): void
    {
        $item = DB::table('card_transaction_sync_items')->where('id', $id)->first();
        if (! $item || $item->status !== 'PENDING') {
            return;
        }
        $locks = [];
        $priorRequestId = request()->attributes->get('request_id');
        try {
            foreach (['card:'.$item->card_id, 'account:'.$item->account_key] as $key) {
                if (! DB::selectOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS acquired', ['card-transaction-sync:'.$key])->acquired) {
                    return; // A later browser step or legacy queue recovery resumes the item.
                }
                $locks[] = $key;
            }
            $item = DB::table('card_transaction_sync_items')->where('id', $id)->first();
            if ($item->status !== 'PENDING' || CarbonImmutable::parse($item->next_attempt_at)->isFuture()) {
                return;
            }
            $batch = DB::table('card_transaction_sync_batches')->where('id', $item->batch_id)->first();
            $context = ['batch_id' => $batch->id, 'request_id' => $batch->request_id,
                'tenant_id' => $item->tenant_id, 'card_id' => $item->card_id, 'page' => $item->next_page];
            request()->attributes->set('request_id', $batch->request_id);
            if (! $this->allowed(AdminUser::find($batch->actor_id))) {
                $this->failed($item, 'permission_revoked', $context);

                return;
            }
            $card = UserCard::with('product.cardProviderReference')->where('tenant_id', $item->tenant_id)->find($item->card_id);
            if (! $card || ! hash_equals($item->binding_hash, $this->binding($card))) {
                $this->failed($item, 'binding_changed', $context);

                return;
            }
            $next = DB::table('card_transaction_sync_accounts')->where('account_key', $item->account_key)->value('next_request_at');
            if ($next && CarbonImmutable::parse($next)->isFuture()) {
                return;
            }
            DB::table('card_transaction_sync_accounts')->upsert([
                'account_key' => $item->account_key, 'next_request_at' => now()->addSecond()->format('Y-m-d H:i:s.uP'),
            ], ['account_key'], ['next_request_at']);
            if ($item->failures >= 4) {
                $this->failed($item, 'worker_timeout', $context);

                return;
            }
            // Durable attempt lease also bounds retries after worker termination.
            DB::table('card_transaction_sync_items')->where('id', $id)->update([
                'failures' => $item->failures + 1, 'error_code' => 'worker_interrupted',
                'next_attempt_at' => now()->addSeconds(120), 'updated_at' => now(),
            ]);
            $startedAt = CarbonImmutable::now();
            try {
                $provider = app(CardProductProviderRouter::class)->forCard($card);
                if (! $provider->available() || $provider->name() !== 'PHOTONPAY') {
                    $this->failed($item, 'provider_unavailable', $context);

                    return;
                }
                $result = $provider->getTransactionPage($card->provider_card_id, $item->next_page, 20);
                if (count($result->items) > 20 || $result->page !== $item->next_page || ($result->hasMore && ($result->items === [] || $item->next_page >= 100000))) {
                    $this->failed($item, 'invalid_pagination', $context);

                    return;
                }
                $from = $batch->date_from.' 00:00:00';
                $until = CarbonImmutable::parse($batch->date_to, 'Asia/Shanghai')->addDay()->format('Y-m-d').' 00:00:00';
                $items = [];
                foreach ($result->items as $row) {
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $row->occurredAt, new \DateTimeZone('Asia/Shanghai'));
                    if (! $date || $date->format('Y-m-d\TH:i:s') !== $row->occurredAt) {
                        $this->failed($item, 'invalid_transaction_time', $context);

                        return;
                    }
                    $wall = $date->format('Y-m-d H:i:s');
                    if ($wall >= $from && $wall < $until) {
                        $items[] = $row;
                    }
                }
                DB::transaction(function () use ($card, $items, $startedAt, $result, $item, $batch) {
                    $fresh = UserCard::whereKey($card->id)->lockForUpdate()->firstOrFail();
                    if (! $this->allowed(AdminUser::find($batch->actor_id)) || ! hash_equals($item->binding_hash, $this->binding($fresh))) {
                        throw new \RuntimeException('Authorization or binding changed');
                    }
                    app(RecordCardTransactionsAction::class)->execute($card, $items, $startedAt);
                    DB::table('card_transaction_sync_items')->where('id', $item->id)->update([
                        'next_page' => $item->next_page + 1, 'pages_processed' => $item->pages_processed + 1,
                        'records_written' => $item->records_written + count($items),
                        'status' => $result->hasMore ? 'PENDING' : 'SUCCEEDED', 'failures' => 0, 'error_code' => null,
                        'next_attempt_at' => now()->addSecond(), 'updated_at' => now(),
                    ]);
                });
                Log::info('Card transaction batch page completed', $context + ['records_written' => count($items)]);
                if ($result->hasMore && $batch->execution_mode === 'queue') {
                    SyncCardTransactionPage::dispatch($id)->onConnection('database')->onQueue('card-transaction-sync')->delay(now()->addSecond());
                }
            } catch (\Throwable $error) {
                $transient = $error instanceof ProviderRateLimitException || $error instanceof ProviderUnavailableException || $error instanceof ProviderUnknownResultException;
                $code = $error instanceof ProviderRateLimitException ? 'provider_rate_limit' : ($transient ? 'provider_read_failed' : 'sync_failed');
                if ($transient && $item->failures < 3) {
                    $delay = [30, 120, 300][$item->failures];
                    DB::table('card_transaction_sync_items')->where('id', $id)->update([
                        'failures' => $item->failures + 1, 'error_code' => $code,
                        'next_attempt_at' => now()->addSeconds($delay), 'updated_at' => now(),
                    ]);
                    Log::warning('Card transaction batch page retry scheduled', $context + ['failure' => $code]);
                    if ($batch->execution_mode === 'queue') {
                        SyncCardTransactionPage::dispatch($id)->onConnection('database')->onQueue('card-transaction-sync')->delay(now()->addSeconds($delay));
                    }
                } else {
                    $this->failed($item, $code, $context);
                }
            }
        } finally {
            request()->attributes->set('request_id', $priorRequestId);
            foreach (array_reverse($locks) as $key) {
                DB::select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', ['card-transaction-sync:'.$key]);
            }
        }
    }

    private function failed(object $item, string $code, array $context): void
    {
        DB::table('card_transaction_sync_items')->where('id', $item->id)->update([
            'status' => 'FAILED', 'error_code' => $code, 'updated_at' => now(),
        ]);
        Log::warning('Card transaction batch failed', $context + ['failure' => $code]);
    }
}
