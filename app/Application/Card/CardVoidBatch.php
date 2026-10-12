<?php

namespace App\Application\Card;

use App\Application\Cleanup\CleanupCompanyFence;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Card\Models\CardManagementOrder;
use App\Domain\Card\Models\UserCard;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\SecurityDeposit\Services\RefundCardPolicy;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Browser-driven, durable financial intents. GET never advances a task. */
final class CardVoidBatch
{
    public function create(AdminUser $actor, array $input): string
    {
        abort_unless(CardVoidPolicy::allowed($actor->fresh()), 403);
        $data = Validator::make($input, ['card_ids' => 'required|array|min:1|max:500', 'card_ids.*' => 'required|uuid|distinct',
            'tenant_id' => 'nullable|uuid|exists:tenants,id', 'request_id' => 'required|uuid', 'confirmed' => 'required|accepted'])->validate();
        $ids = array_map('strtolower', $data['card_ids']);
        sort($ids);
        $hash = hash('sha256', json_encode([$ids, $data['tenant_id'] ?? null]));
        try {
            $companies = UserCard::whereIn('id', $ids)->distinct()->orderBy('tenant_id')->pluck('tenant_id')->all();

            return $this->fenced($companies, fn () => DB::transaction(function () use ($actor, $data, $ids, $hash): string {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['card-void-create:'.$actor->id]);
                abort_unless(CardVoidPolicy::allowed($actor->fresh()), 403);
                $old = DB::table('card_void_batches')->where('actor_id', $actor->id)->where('request_id', $data['request_id'])->first();
                if ($old) {
                    if (! hash_equals($old->request_hash, $hash)) {
                        throw new DomainException('CARD_VOID_REQUEST_CONFLICT', 'This request was already used with different details.', 409);
                    }

                    return $old->id;
                }
                $cards = UserCard::whereIn('id', $ids)->when($data['tenant_id'] ?? null, fn ($q, $tenant) => $q->where('tenant_id', $tenant))
                    ->orderBy('tenant_id')->orderBy('user_id')->orderBy('id')->get();
                abort_unless($cards->count() === count($ids), 404);
                $id = (string) Str::uuid();
                $at = now();
                DB::table('card_void_batches')->insert(['id' => $id, 'actor_id' => $actor->id, 'request_id' => $data['request_id'],
                    'request_hash' => $hash, 'tenant_id' => $data['tenant_id'] ?? null, 'created_at' => $at, 'updated_at' => $at]);
                foreach ($cards as $snapshot) {
                    // Creation reserves identities only; eligibility/provider checks occur on advance.
                    Tenant::whereKey($snapshot->tenant_id)->lockForUpdate()->firstOrFail();
                    User::where('tenant_id', $snapshot->tenant_id)->whereKey($snapshot->user_id)->lockForUpdate()->firstOrFail();
                    $card = UserCard::where('tenant_id', $snapshot->tenant_id)->where('user_id', $snapshot->user_id)->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
                    CardVoidPolicy::assertAllowed($card->tenant_id, $card->user_id, $card->id);
                    DB::table('card_void_items')->insert(['id' => (string) Str::uuid(), 'batch_id' => $id, 'tenant_id' => $card->tenant_id,
                        'user_id' => $card->user_id, 'card_id' => $card->id, 'binding_hash' => $this->binding($card),
                        'preview_balance' => $card->availableBalance(), 'next_attempt_at' => $at, 'created_at' => $at, 'updated_at' => $at]);
                    app(AuditLogger::class)->record($card->tenant_id, 'ADMIN', $actor->id, 'CARD_VOID_REQUESTED', 'user_card', $card->id,
                        null, ['batch_id' => $id], $data['request_id']);
                }

                return $id;
            }));
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23505') {
                throw new DomainException('CARD_VOID_PENDING', 'This card is being voided. Wait for the operation to finish.', 409);
            }
            throw $e;
        }
    }

    public function advance(AdminUser $actor, string $batch): array
    {
        $this->owned($actor, $batch);
        $key = 'card-void-advance:'.$batch;
        if (! DB::scalar('SELECT pg_try_advisory_lock(hashtextextended(?,0))', [$key])) {
            return $this->show($actor, $batch);
        }
        try {
            $item = DB::transaction(function () use ($batch) {
                $row = DB::table('card_void_items')->where('batch_id', $batch)->whereIn('status', ['PENDING', 'RUNNING', 'UNKNOWN'])
                    ->where('next_attempt_at', '<=', now())->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
                    ->orderBy('next_attempt_at')->orderBy('id')->lockForUpdate()->first();
                if (! $row) {
                    return null;
                }
                $token = (string) Str::uuid();
                DB::table('card_void_items')->where('id', $row->id)->update(['status' => 'RUNNING', 'lease_token' => $token,
                    'lease_until' => now()->addMinutes(2), 'updated_at' => now()]);

                return DB::table('card_void_items')->where('id', $row->id)->first();
            });
            if ($item) {
                try {
                    app(CleanupCompanyFence::class)->run($item->tenant_id, fn () => $this->step($actor, $item));
                } catch (DomainException $e) {
                    $unconfirmed = CardManagementOrder::where('tenant_id', $item->tenant_id)->where('user_id', $item->user_id)->where('card_id', $item->card_id)
                        ->where('request_id', CardVoidPolicy::operationId($item, $item->stage))->whereIn('status', ['PROCESSING', 'UNKNOWN'])->exists();
                    if ($unconfirmed) {
                        $this->pending($item, $e->errorCode);
                    } else {
                        $this->patch($item, ['status' => 'FAILED', 'error_code' => $e->errorCode]);
                    }
                } catch (\Throwable) {
                    // An interrupted write may have reached the provider. Keep its intent/reservation.
                    $this->patch($item, ['status' => 'UNKNOWN', 'error_code' => 'CARD_VOID_UNCONFIRMED', 'next_attempt_at' => now()->addSeconds(10)]);
                } finally {
                    DB::table('card_void_items')->where('id', $item->id)->where('lease_token', $item->lease_token)
                        ->update(['lease_token' => null, 'lease_until' => null, 'updated_at' => now()]);
                }
            }
        } finally {
            DB::select('SELECT pg_advisory_unlock(hashtextextended(?,0))', [$key]);
        }

        return $this->show($actor, $batch);
    }

    private function step(AdminUser $actor, object $item): void
    {
        $this->owned($actor, $item->batch_id);
        $card = app(CardManagementAccess::class)->card($item->tenant_id, $item->user_id, $item->card_id);
        if ($this->binding($card) !== $item->binding_hash || $card->archived_at !== null) {
            throw new DomainException('CARD_VOID_BINDING_CHANGED', 'The card is unavailable for voiding.', 409);
        }
        RefundCardPolicy::assertAllowed($item->tenant_id, $item->user_id, $item->card_id);
        $manage = app(ManageCardAction::class);
        if ($item->stage === 'CHECK') {
            if (CardManagementOrder::where('tenant_id', $item->tenant_id)->where('card_id', $item->card_id)->whereNotIn('status', ['SUCCEEDED', 'FAILED', 'EXPIRED'])->exists()) {
                throw new DomainException('CARD_OPERATION_OUTSTANDING', 'Wait for the current card operation to finish.', 409);
            }
            app(CardManagementAccess::class)->wallet($item->tenant_id, $item->user_id);
            $card = app(RefreshManagedCardAction::class)->execute($item->tenant_id, $item->user_id, $item->card_id);
            if ($card->provider_status === 'normal') {
                $this->next($item, 'FREEZE');

                return;
            }
            if ($card->provider_status === 'frozen' || ($card->provider_status === 'expired' && $card->provider_balance !== null && Money::of($card->provider_balance, 'USD')->isZero())) {
                $this->next($item, 'RETURN', ['return_amount' => $card->provider_balance]);

                return;
            }
            throw new DomainException('CARD_STATUS_UNAVAILABLE', 'This operation is not available for the current card status.', 409);
        }
        if (in_array($item->stage, ['FREEZE', 'RETURN', 'CANCEL'], true)) {
            $request = CardVoidPolicy::operationId($item, $item->stage);
            $order = CardManagementOrder::where('tenant_id', $item->tenant_id)->where('user_id', $item->user_id)->where('card_id', $item->card_id)->where('request_id', $request)->first();
            if ($item->stage === 'RETURN' && ! $order && $item->return_amount === null) {
                throw new DomainException('CARD_VOID_UNCONFIRMED', 'Card voiding is awaiting confirmation.', 409);
            }
            if ($item->stage === 'RETURN' && Money::of($item->return_amount, 'USD')->isZero()) {
                $this->next($item, 'OVERFLOW');

                return;
            }
            $amount = null;
            if ($item->stage === 'RETURN') {
                try {
                    $amount = (string) BigDecimal::of($item->return_amount)->toScale(2);
                } catch (\Throwable) {
                    throw new DomainException('CARD_VOID_PRECISION', 'The provider balance requires manual review before voiding.', 409);
                }
            }
            if ($item->stage === 'CANCEL' && ! $order) {
                $card = app(RefreshManagedCardAction::class)->execute($item->tenant_id, $item->user_id, $item->card_id);
                if ($card->provider_balance === null || ! Money::of($card->provider_balance, 'USD')->isZero() || ! Money::of($card->overflowBalance(), 'USD')->isZero()) {
                    $this->pending($item, 'CARD_VOID_BALANCE_REMAINS');

                    return;
                }
            }
            $order = $order ? ($order->terminal() ? $order : $manage->sync($item->tenant_id, $order->id))
                : $manage->operate($item->tenant_id, $item->user_id, $item->card_id, $request, $item->stage, $amount, voidItemId: $item->id);
            if ($order->status === 'FAILED' || $order->status === 'EXPIRED') {
                throw new DomainException('CARD_VOID_PROVIDER_REJECTED', 'The provider rejected this card voiding step.', 409);
            }
            if ($order->status !== 'SUCCEEDED') {
                $this->pending($item, 'CARD_VOID_UNCONFIRMED');

                return;
            }
            if ($item->stage === 'FREEZE') {
                $card = app(RefreshManagedCardAction::class)->execute($item->tenant_id, $item->user_id, $item->card_id);
                if ($card->provider_status !== 'frozen' || $card->provider_balance === null) {
                    $this->pending($item, 'CARD_VOID_UNCONFIRMED');

                    return;
                }
                $this->next($item, 'RETURN', ['return_amount' => $card->provider_balance]);
            } else {
                $this->next($item, $item->stage === 'RETURN' ? 'OVERFLOW' : 'VERIFY');
            }

            return;
        }
        $card = app(RefreshManagedCardAction::class)->execute($item->tenant_id, $item->user_id, $item->card_id);
        if ($card->provider_balance === null || ! Money::of($card->provider_balance, 'USD')->isZero()) {
            $this->pending($item, 'CARD_VOID_BALANCE_REMAINS');

            return;
        }
        if ($item->stage === 'OVERFLOW') {
            app(ReturnCardVoidOverflow::class)->execute($item, $actor);
            $this->next($item, 'CANCEL');

            return;
        }
        if ($item->stage === 'VERIFY') {
            if ($card->provider_status !== 'cancelled' || ! Money::of($card->overflowBalance(), 'USD')->isZero()
                || CardManagementOrder::where('tenant_id', $item->tenant_id)->where('card_id', $item->card_id)->whereNotIn('status', ['SUCCEEDED', 'FAILED', 'EXPIRED'])->exists()) {
                $this->pending($item, 'CARD_VOID_UNCONFIRMED');

                return;
            }
            DB::transaction(function () use ($item, $actor): void {
                $owned = app(CardManagementAccess::class)->card($item->tenant_id, $item->user_id, $item->card_id, lock: true);
                abort_unless(DB::table('card_void_items')->where('id', $item->id)->where('lease_token', $item->lease_token)->where('lease_until', '>', now())->where('status', 'RUNNING')->lockForUpdate()->exists(), 409);
                abort_unless($owned->provider_status === 'cancelled' && $owned->provider_balance !== null && Money::of($owned->provider_balance, 'USD')->isZero()
                    && Money::of($owned->overflowBalance(), 'USD')->isZero()
                    && ! CardManagementOrder::where('tenant_id', $item->tenant_id)->where('card_id', $item->card_id)->whereNotIn('status', ['SUCCEEDED', 'FAILED', 'EXPIRED'])->exists(), 409);
                // Archive only a verified empty/cancelled card. All original history remains.
                $owned->forceFill(['archived_at' => now()])->save();
                $this->patch($item, ['status' => 'SUCCEEDED', 'stage' => 'DONE', 'error_code' => null, 'lease_token' => null, 'lease_until' => null]);
                app(AuditLogger::class)->record($item->tenant_id, 'ADMIN', $actor->id, 'CARD_VOID_SUCCEEDED', 'user_card', $item->card_id, null,
                    ['batch_id' => $item->batch_id, 'item_id' => $item->id, 'archived_at' => $owned->archived_at->toIso8601String()]);
            });
        }
    }

    public function retry(AdminUser $actor, string $batch, array $input): void
    {
        $this->owned($actor, $batch);
        Validator::make($input, ['confirmed' => 'required|accepted'])->validate();
        $companies = DB::table('card_void_items')->where('batch_id', $batch)->distinct()->orderBy('tenant_id')->pluck('tenant_id')->all();
        $this->fenced($companies, fn () => DB::transaction(function () use ($actor, $batch): void {
            foreach (DB::table('card_void_items')->where('batch_id', $batch)->where('status', 'FAILED')->orderBy('tenant_id')->orderBy('user_id')->orderBy('card_id')->get() as $item) {
                app(CardManagementAccess::class)->card($item->tenant_id, $item->user_id, $item->card_id, lock: true, requireProvider: false);
                $item = DB::table('card_void_items')->where('id', $item->id)->where('status', 'FAILED')->lockForUpdate()->first();
                if (! $item) {
                    continue;
                }
                if (DB::table('card_void_items')->where('card_id', $item->card_id)->where('id', '<>', $item->id)->whereIn('status', ['PENDING', 'RUNNING', 'UNKNOWN'])->exists()) {
                    continue;
                }
                DB::table('card_void_items')->where('id', $item->id)->update(['status' => 'PENDING', 'stage' => 'CHECK', 'attempt' => $item->attempt + 1,
                    'return_amount' => null, 'error_code' => null, 'next_attempt_at' => now(), 'updated_at' => now()]);
                app(AuditLogger::class)->record($item->tenant_id, 'ADMIN', $actor->id, 'CARD_VOID_RETRY_CONFIRMED', 'user_card', $item->card_id, null,
                    ['batch_id' => $batch, 'item_id' => $item->id, 'attempt' => $item->attempt + 1]);
            }
        }));
    }

    public function show(AdminUser $actor, string $batch): array
    {
        $row = $this->owned($actor, $batch);
        $items = DB::table('card_void_items as i')->join('user_cards as c', fn ($j) => $j->on('c.id', '=', 'i.card_id')->on('c.tenant_id', '=', 'i.tenant_id')->on('c.user_id', '=', 'i.user_id'))
            ->join('tenants as t', 't.id', '=', 'i.tenant_id')->join('users as u', fn ($j) => $j->on('u.id', '=', 'i.user_id')->on('u.tenant_id', '=', 'i.tenant_id'))
            ->where('i.batch_id', $batch)->orderBy('i.created_at')->orderBy('i.id')->get(['i.id', 'i.status', 'i.stage', 'i.preview_balance', 'i.error_code', 'c.masked_pan', 't.name as company', 'u.account_id']);
        $ids = $items->pluck('id');
        $returns = DB::table('audit_logs as a')->join('card_management_orders as o', fn ($j) => $j->on('o.id', '=', 'a.resource_id')->on('o.tenant_id', '=', 'a.tenant_id'))
            ->join('card_void_items as i', fn ($j) => $j->whereRaw("i.id::text=a.after_data->>'void_item_id'")->on('i.tenant_id', '=', 'o.tenant_id')->on('i.user_id', '=', 'o.user_id')->on('i.card_id', '=', 'o.card_id'))
            ->where('a.action', 'CARD_MANAGEMENT_REQUESTED')->where('a.actor_type', 'ADMIN')->where('o.kind', 'RETURN')->where('o.status', 'SUCCEEDED')->whereIn('i.id', $ids)
            ->select('i.id')->selectRaw('SUM(o.arrival_amount)::text AS returned, SUM(o.fee_amount)::text AS fee')->groupBy('i.id')->get()->keyBy('id');
        $overflow = DB::table('card_overflow_movements')->whereIn('void_item_id', $ids)->where('kind', 'RETURN')->select('void_item_id')->selectRaw('SUM(amount)::text AS amount')->groupBy('void_item_id')->pluck('amount', 'void_item_id');
        $counts = array_fill_keys(['PENDING', 'RUNNING', 'UNKNOWN', 'SUCCEEDED', 'FAILED'], 0);
        foreach ($items as $item) {
            $counts[$item->status]++;
            $item->returned = (string) BigDecimal::of($returns->get($item->id)?->returned ?? '0')->plus($overflow[$item->id] ?? '0')->toScale(8);
            $item->fee = $returns->get($item->id)?->fee ?? '0.00000000';
        }

        return ['id' => $batch, 'createdAt' => $row->created_at, 'counts' => $counts, 'items' => $items];
    }

    public function recent(AdminUser $actor): array
    {
        abort_unless(CardVoidPolicy::allowed($actor->fresh()), 403);

        return DB::table('card_void_batches')->where('actor_id', $actor->id)->orderByDesc('created_at')->limit(20)->get(['id', 'created_at'])->all();
    }

    private function owned(AdminUser $actor, string $batch): object
    {
        abort_unless(CardVoidPolicy::allowed($actor->fresh()), 403);
        $row = DB::table('card_void_batches')->where('actor_id', $actor->id)->where('id', $batch)->first();
        abort_unless($row, 404);

        return $row;
    }

    private function fenced(array $companies, callable $operation): mixed
    {
        if ($companies === []) {
            return $operation();
        }
        $company = array_shift($companies);

        return app(CleanupCompanyFence::class)->run($company, fn () => $this->fenced($companies, $operation));
    }

    private function binding(UserCard $card): string
    {
        return hash('sha256', json_encode([$card->tenant_id, $card->user_id, $card->card_product_id, $card->product->card_provider_reference_id, $card->provider, $card->provider_card_id, $card->form_factor]));
    }

    private function next(object $item, string $stage, array $extra = []): void
    {
        $this->patch($item, $extra + ['stage' => $stage, 'status' => 'PENDING', 'error_code' => null, 'next_attempt_at' => now()]);
    }

    private function pending(object $item, string $error): void
    {
        $this->patch($item, ['status' => 'UNKNOWN', 'error_code' => $error, 'next_attempt_at' => now()->addSeconds(10)]);
    }

    private function patch(object $item, array $values): void
    {
        DB::table('card_void_items')->where('id', $item->id)->where('lease_token', $item->lease_token)->update($values + ['updated_at' => now()]);
    }
}
