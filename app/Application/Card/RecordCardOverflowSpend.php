<?php

namespace App\Application\Card;

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Card\Models\CardManagementOrder;
use App\Domain\Card\Models\UserCard;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class RecordCardOverflowSpend
{
    public function execute(string $tenantId, string $cardId, array $data, AdminUser $actor): void
    {
        abort_unless($actor->fresh()->status === AdminUserStatus::Active, 403);
        abort_unless(app(AuthorizationService::class)->allows($actor, ScopeType::Platform, null, 'card_product.manage'), 403);
        $amount = Money::of($data['amount'], 'USDT');
        if (! $amount->isPositive()) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount greater than zero.']);
        }
        $card = UserCard::query()->where('tenant_id', $tenantId)->whereKey($cardId)->firstOrFail();
        $existing = DB::table('card_overflow_movements')->where('tenant_id', $tenantId)->where('card_id', $cardId)->where('request_id', $data['request_id'])->first();
        $completed = CardManagementOrder::query()->where('tenant_id', $tenantId)->where('card_id', $cardId)->where('status', 'SUCCEEDED')->count();
        $generation = null;
        if (! $existing) {
            $generation = app(RefreshManagedCardAction::class)->execute($tenantId, $card->user_id, $cardId)->refresh_generation;
        }
        DB::transaction(function () use ($tenantId, $cardId, $card, $data, $actor, $amount, $completed, $generation): void {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            User::query()->where('tenant_id', $tenantId)->whereKey($card->user_id)->lockForUpdate()->firstOrFail();
            $owned = UserCard::query()->where('tenant_id', $tenantId)->whereKey($cardId)->lockForUpdate()->firstOrFail();
            $lockedActor = AdminUser::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedActor->status === AdminUserStatus::Active, 403);
            $actor->memberships()->where('scope_type', ScopeType::Platform)->lockForUpdate()->get();
            abort_unless(app(AuthorizationService::class)->allows($actor, ScopeType::Platform, null, 'card_product.manage'), 403);
            $existing = DB::table('card_overflow_movements')->where('tenant_id', $tenantId)->where('card_id', $cardId)->where('request_id', $data['request_id'])->first();
            if ($existing) {
                if ($existing->kind !== 'SPEND' || Money::of($existing->amount, 'USDT')->compare($amount) !== 0 || $existing->note !== $data['note'] || $existing->actor_id !== $actor->id) {
                    throw ValidationException::withMessages(['amount' => 'This request was already used with different details.']);
                }

                return;
            }
            if ($owned->refresh_generation !== $generation || CardManagementOrder::query()->where('tenant_id', $tenantId)->where('card_id', $cardId)->where('status', 'SUCCEEDED')->count() !== $completed) {
                throw ValidationException::withMessages(['amount' => 'The card changed during refresh. Please try again.']);
            }
            if ($owned->provider_balance === null || ! Money::of($owned->provider_balance, 'USD')->isZero()) {
                throw ValidationException::withMessages(['amount' => 'Use the card provider balance first. Refresh after it is exhausted.']);
            }
            if (CardManagementOrder::query()->where('tenant_id', $tenantId)->where('card_id', $cardId)->whereIn('status', ['PROCESSING', 'UNKNOWN'])->exists()) {
                throw ValidationException::withMessages(['amount' => 'Wait for the current card operation to finish.']);
            }
            if ($amount->compare(Money::of($owned->overflowBalance(), 'USDT')) > 0) {
                throw ValidationException::withMessages(['amount' => 'The amount exceeds the card overflow balance.']);
            }
            $wallet = Wallet::query()->where('tenant_id', $tenantId)->where('user_id', $owned->user_id)->where('asset_code', 'USDT')->firstOrFail();
            app(CardOverflowLedger::class)->spend($owned, $wallet->id, $amount->amount(), $data['request_id'], $actor->id, $data['note']);
            app(AuditLogger::class)->record($tenantId, 'ADMIN', $actor->id, 'CARD_OVERFLOW_SPEND_RECORDED', 'user_card', $cardId,
                after: ['amount' => $amount->amount(), 'note' => $data['note'], 'operation_request_id' => $data['request_id'], 'recorded_at' => now()->toIso8601String()]);
        });
    }
}
