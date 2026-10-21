<?php

namespace App\Application\Payment;

use App\Application\Assets\AssetAccess;
use App\Application\Partners\PartnerManagement;
use App\Application\Partners\UserAdvances;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Assets\AssetDepositOrder;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ChangeDepositReceiptTypeAction
{
    public function execute(AdminUser $actor, string $tenant, string $source, string $id, string $type, int $revision, string $request, bool $confirmed): void
    {
        $access = app(AssetAccess::class);
        $access->platform($actor, 'wallet_topups.confirm');
        $access->platform($actor, 'partners.manage');
        AssetAccess::requestId($request);
        app(ManualDepositReceipt::class)->validateType($type);
        if (! $confirmed || $revision < 0 || ! in_array($source, ['primary', 'asset'], true)) {
            throw new DomainException('VALIDATION_FAILED', 'Confirm the receipt type change.', 422);
        }
        $hash = hash('sha256', json_encode([$tenant, $source, $id, $type, $revision]));
        DB::transaction(function () use ($access, $actor, $tenant, $source, $id, $type, $revision, $request, $hash): void {
            $access->platform($actor, 'wallet_topups.confirm');
            $access->platform($actor, 'partners.manage');
            AssetAccess::lock('deposit-receipt-change:'.$actor->id.':'.$request);
            $class = $source === 'primary' ? WalletTopupOrder::class : AssetDepositOrder::class;
            $original = $class::where('tenant_id', $tenant)->findOrFail($id);
            [$company] = $access->settlementOwners($tenant, $original->user_id);
            $order = $class::where('tenant_id', $tenant)->lockForUpdate()->findOrFail($id);
            $retry = DB::table('deposit_receipt_type_changes')->where('actor_id', $actor->id)->where('request_id', $request)->first();
            if ($retry) {
                if ($retry->request_hash !== $hash) {
                    throw new DomainException('IDEMPOTENCY_CONFLICT', 'This request was used with a different receipt type or order.', 409);
                }

                return;
            }
            if ($order->getRawOriginal('status') !== 'CREDITED' || ! $order->ledger_entry_id) {
                throw new DomainException('RECEIPT_TYPE_CHANGE_NOT_ALLOWED', 'Only credited deposits can change receipt type.', 422);
            }
            $previous = DB::table('deposit_receipt_type_changes')->where('tenant_id', $tenant)->where('source', $source)->where('order_id', $id)->orderByDesc('revision')->first();
            if ($revision !== (int) ($previous?->revision ?? 0)) {
                throw new DomainException('RECEIPT_TYPE_REVISION_STALE', 'Receipt type changed. Refresh the order and try again.', 409);
            }
            $beforeType = $previous?->after_type ?? $order->manual_receipt_type ?? 'ACTUAL';
            $beforeJournal = $previous ? $previous->after_journal_id : $order->advance_journal_id;
            $beforeUserJournal = $previous ? $previous->after_user_journal_id : $order->user_advance_journal_id;
            if ($beforeType === $type) {
                throw new DomainException('VALIDATION_FAILED', 'Choose a different receipt type.', 422);
            }
            $amount = Money::of($order->actual_received_amount ?? $order->amount, $order->asset_code)->amount();
            $journal = null;
            $reversal = null;
            $userJournal = null;
            $userReversal = null;
            if ($type === 'ADVANCE') {
                $attributes = app(ManualDepositReceipt::class)->attributes($order, $actor, 'ADVANCE', $amount, true);
                $journal = $attributes['advance_journal_id'];
                $userJournal = $attributes['user_advance_journal_id'];
            } elseif ($beforeUserJournal) {
                $originalJournal = DB::table('user_advance_entries')->where('tenant_id', $tenant)->where('user_id', $order->user_id)->where('id', $beforeUserJournal)->firstOrFail();
                abort_if($originalJournal->reverses_id || ! BigDecimal::of($originalJournal->amount)->isEqualTo($amount), 409);
                $userReversal = app(UserAdvances::class)->record($actor, $tenant, $order->user_id, [
                    'amount' => $amount, 'confirmed' => true, 'business_date' => now()->setTimezone('Asia/Shanghai')->format('Y-m-d'),
                    'note' => 'Deposit receipt type changed to actual: '.$order->getTable().'/'.$id,
                    'request_id' => (string) Str::uuid(), 'reverses_id' => $beforeUserJournal,
                ], true)->id;
            } else {
                $originalJournal = DB::table('partner_journal_entries')->where('tenant_id', $tenant)->where('id', $beforeJournal)->firstOrFail();
                if ($originalJournal->kind !== 'ADVANCE' || $originalJournal->reverses_id || ! BigDecimal::of($originalJournal->amount)->isEqualTo($amount)) {
                    throw new DomainException('RECEIPT_TYPE_CHANGE_NOT_ALLOWED', 'Advance receipt evidence could not be confirmed.', 409);
                }
                DB::table('partner_configurations')->where('tenant_id', $tenant)->where('user_id', $order->user_id)->where('id', $originalJournal->partner_id)->lockForUpdate()->firstOrFail();
                $reversal = DB::table('partner_journal_entries')->where('tenant_id', $tenant)->where('reverses_id', $beforeJournal)->value('id');
                if (! $reversal) {
                    $reversal = app(PartnerManagement::class)->journal($actor, $tenant, $originalJournal->partner_id, [
                        'kind' => 'ADVANCE', 'amount' => $amount, 'business_date' => now()->setTimezone('Asia/Shanghai')->format('Y-m-d'),
                        'note' => 'Deposit receipt type changed to actual: '.$order->getTable().'/'.$id,
                        'request_id' => (string) Str::uuid(), 'reverses_id' => $beforeJournal,
                    ])->id;
                }
            }
            $change = ['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'user_id' => $order->user_id, 'source' => $source, 'order_id' => $id,
                'revision' => $revision + 1, 'before_type' => $beforeType, 'after_type' => $type, 'amount' => $amount, 'asset_code' => $order->asset_code,
                'before_journal_id' => $beforeJournal, 'after_journal_id' => $journal, 'reversal_journal_id' => $reversal,
                'before_user_journal_id' => $beforeUserJournal, 'after_user_journal_id' => $userJournal, 'reversal_user_journal_id' => $userReversal,
                'actor_id' => $actor->id, 'request_id' => $request, 'request_hash' => $hash, 'created_at' => now()];
            DB::table('deposit_receipt_type_changes')->insert($change);
            app(AuditLogger::class)->record($tenant, 'ADMIN', $actor->id, 'DEPOSIT_RECEIPT_TYPE_CHANGED',
                $source === 'primary' ? 'wallet_topup_order' : 'asset_deposit_order', $id,
                ['receipt_type' => $beforeType, 'revision' => $revision, 'advance_journal_id' => $beforeJournal],
                ['receipt_type' => $type, 'revision' => $revision + 1, 'amount' => $amount, 'asset' => $order->asset_code,
                    'change_id' => $change['id'], 'advance_journal_id' => $journal, 'reversal_journal_id' => $reversal, 'user_advance_journal_id' => $userJournal, 'user_reversal_journal_id' => $userReversal], $request);
        });
    }
}
