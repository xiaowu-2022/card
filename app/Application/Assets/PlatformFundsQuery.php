<?php

namespace App\Application\Assets;

use App\Application\Admin\FinancialOperationQuery;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Assets\AssetDepositOrder;
use App\Domain\Assets\AssetWithdrawalOrder;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Domain\Withdrawal\Models\WithdrawalOrder;
use App\Domain\Withdrawal\Services\WithdrawalAddressProtector;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** A read-only union; every mutation still uses its original scoped application action. */
final class PlatformFundsQuery
{
    public const DEPOSIT_STATUSES = ['PENDING', 'CONFIRMING', 'PROCESSING', 'UNKNOWN', 'PAID', 'CREDITED', 'FAILED', 'CANCELLED', 'EXPIRED', 'REFUNDED', 'REQUIRES_REVIEW'];

    public const WITHDRAWAL_STATUSES = ['PENDING', 'APPROVED', 'PROCESSING', 'VERIFYING', 'UNKNOWN', 'COMPLETED', 'CANCELLED', 'REJECTED'];

    public function filtered(string $mode, array $filters): Builder
    {
        $withdrawal = $mode === 'withdrawal';
        $primary = $withdrawal ? 'withdrawal_orders' : 'wallet_topup_orders';
        $assetTable = $withdrawal ? 'asset_withdrawal_orders' : 'asset_deposit_orders';
        $time = $withdrawal ? 'requested_at' : 'created_at';
        // VERIFYING remains distinct from UNKNOWN; only the successful display label is unified.
        $status = $withdrawal ? "CASE WHEN status = 'SUCCEEDED' THEN 'COMPLETED' ELSE status END" : 'status';
        $union = DB::table($primary)->selectRaw("id, tenant_id, user_id, asset_code, network_code as network, {$status} as status, {$time} as ordered_at, 'primary' as source")
            ->unionAll(DB::table($assetTable)->selectRaw("id, tenant_id, user_id, asset_code, network, status, created_at as ordered_at, 'asset' as source"));
        $query = DB::query()->fromSub($union, 'o')
            ->join('tenants as t', 't.id', '=', 'o.tenant_id')
            ->join('users as u', fn ($join) => $join->on('u.id', '=', 'o.user_id')->on('u.tenant_id', '=', 'o.tenant_id'))
            ->select('o.*', 't.name as company_name', 'u.account_id', 'u.email');
        foreach (['user' => 'o.user_id', 'company' => 'o.tenant_id', 'asset' => 'o.asset_code', 'network' => 'o.network', 'status' => 'o.status'] as $filter => $column) {
            $query->when($filters[$filter] ?? null, fn ($q, $value) => $q->where($column, $value));
        }
        $query->when($filters['search'] ?? null, function ($q, $search): void {
            $pattern = '%'.addcslashes($search, '%_').'%';
            $q->where(fn ($q) => $q->where('u.email', 'ilike', $pattern)->orWhere('u.account_id', 'like', $pattern)
                ->orWhereRaw("replace(o.id::text, '-', '') ilike ?", ['%'.str_replace('-', '', addcslashes($search, '%_')).'%']));
        });

        return $query->orderByDesc('o.ordered_at')->orderBy('o.source')->orderBy('o.id');
    }

    public function paginate(string $mode, array $filters)
    {
        $withdrawal = $mode === 'withdrawal';
        $page = $this->filtered($mode, $filters)->paginate(25)->withQueryString();
        $models = [];
        foreach (['primary' => $withdrawal ? WithdrawalOrder::class : WalletTopupOrder::class, 'asset' => $withdrawal ? AssetWithdrawalOrder::class : AssetDepositOrder::class] as $source => $class) {
            $builder = $class::query()->whereIn('id', $page->getCollection()->where('source', $source)->pluck('id'));
            if ($withdrawal && $source === 'primary') {
                $builder->with('destination');
            }
            $models[$source] = $builder->get()->keyBy('id');
        }

        return $page->through(function ($row) use ($models, $withdrawal) {
            $o = $models[$row->source][$row->id];
            $primary = $row->source === 'primary';
            $type = $primary ? ($withdrawal ? 'withdrawal_order' : 'wallet_topup_order') : ($withdrawal ? 'asset_withdrawal_order' : 'asset_deposit_order');
            $actor = $withdrawal ? ($primary ? $o->reviewed_by_admin_user_id : $o->reviewed_by) : $o->manual_confirmed_by;
            $operatedAt = $withdrawal ? $o->reviewed_at : $o->manual_confirmed_at;
            $arrival = $primary ? ($withdrawal ? $o->blockchain_confirmed_at : $o->credited_at) : null;
            if (! $primary && in_array($row->status, ['CREDITED', 'COMPLETED'], true) && $o->ledger_entry_id) {
                $arrival = DB::table('ledger_entries')->where('tenant_id', $o->tenant_id)->where('id', $o->ledger_entry_id)->value('created_at');
            }

            return [
                'id' => $o->id, 'source' => $row->source, 'legacy' => $primary,
                'receiptType' => $withdrawal ? null : ($o->manual_receipt_type ?? 'ACTUAL'),
                'advanceJournalId' => $withdrawal ? null : $o->advance_journal_id,
                'canAdvance' => ! $withdrawal && $o->asset_code === 'USDT' && DB::table('partner_configurations')->where('tenant_id', $o->tenant_id)->where('user_id', $o->user_id)->where('enabled', true)->exists(),
                'manuallyConfirmed' => ! $withdrawal && $o->manual_confirmed_at !== null,
                'canConfirm' => ! $withdrawal && ($primary
                    ? $o->payment_rail === 'TRC20_SHARED' && $o->asset_code === 'USDT' && in_array($row->status, ['PENDING', 'PROCESSING', 'UNKNOWN'], true)
                    : in_array($row->status, ['PENDING', 'CONFIRMING'], true)),
                'canRecheck' => ! $withdrawal && ($primary
                    ? $o->payment_rail === 'TRC20_SHARED' && $o->asset_code === 'USDT' && in_array($row->status, ['PENDING', 'PROCESSING', 'PAID', 'EXPIRED'], true)
                    : in_array($row->status, ['PENDING', 'CONFIRMING', 'REQUIRES_REVIEW', 'EXPIRED'], true)),
                'reference' => strtoupper(substr(str_replace('-', '', $o->id), 0, 12)),
                'tenant_id' => $o->tenant_id, 'company' => $row->company_name,
                'companyId' => $o->tenant_id, 'companyName' => $row->company_name,
                'accountId' => $row->account_id, 'userEmail' => $row->email,
                'asset' => $o->asset_code, 'network' => $row->network,
                'actualReceivedAmount' => $withdrawal ? null : $o->actual_received_amount,
                'amount' => $o->amount, 'status' => $row->status,
                'created_at' => CarbonImmutable::parse($row->ordered_at)->toIso8601String(),
                'arrival_at' => $arrival ? CarbonImmutable::parse($arrival)->toIso8601String() : null,
                'operator' => $actor ? AdminUser::find($actor)?->name : null,
                'operated_at' => $operatedAt?->toIso8601String(),
                'fee' => $withdrawal ? $o->fee_amount : null,
                'address' => $withdrawal ? ($primary ? $o->destination?->masked_address : app(WithdrawalAddressProtector::class)->mask($o->address)) : ($primary ? $o->deposit_address : $o->address),
                'tx_hash' => $o->submitted_tx_hash ?? '',
                'operations' => app(FinancialOperationQuery::class)->forOrder($o->tenant_id, $type, $o->id),
            ];
        });
    }
}
