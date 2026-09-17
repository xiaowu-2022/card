<?php

namespace App\Application\Promotion;

use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CompanyFundBookQuery
{
    public function execute(string $tenantId, ?string $date, int $page): array
    {
        $tenant = Tenant::query()->whereKey($tenantId)->firstOrFail();
        $day = $date ? CarbonImmutable::createFromFormat('!Y-m-d', $date, $tenant->timezone) : CarbonImmutable::now($tenant->timezone)->startOfDay();
        $base = DB::table('ledger_entries as e')->join('ledger_postings as p', fn ($join) => $join->on('p.ledger_entry_id', '=', 'e.id')->on('p.tenant_id', '=', 'e.tenant_id'))
            ->join('ledger_accounts as a', fn ($join) => $join->on('a.id', '=', 'p.ledger_account_id')->on('a.tenant_id', '=', 'e.tenant_id'))
            ->where('e.tenant_id', $tenantId)->whereNotNull('e.sealed_at')->where('e.asset_code', 'USDT');
        $daily = (clone $base)->where('e.posted_at', '>=', $day->utc())->where('e.posted_at', '<', $day->addDay()->utc());
        $sum = fn ($query, $type, $account) => (string) ((clone $query)->whereIn('e.event_type', (array) $type)->where('a.account_type', $account)->selectRaw("CASE WHEN COUNT(*) = 0 THEN '0' ELSE SUM(ABS(p.delta))::numeric(20,8)::text END AS amount")->value('amount'));
        $totals = fn ($query) => ['topups' => $sum($query, ['WALLET_TOPUP_CREDIT', 'ASSET_DEPOSIT'], 'USER_AVAILABLE'),
            'withdrawals' => $sum($query, ['WITHDRAWAL_SETTLE', 'ASSET_WITHDRAWAL_SETTLE'], 'TENANT_WITHDRAWAL_CLEARING'),
            'activationCommissions' => $sum($query, 'COMMISSION_EARN', 'TENANT_COMMISSION_CLEARING'),
            'annualFees' => $sum($query, 'PROMOTION_ANNUAL_FEE', 'TENANT_PROMOTION_FEE_REVENUE'), 'annualRebates' => $sum($query, 'PROMOTION_FEE_REBATE', 'USER_AVAILABLE'), 'annualCommissions' => $sum($query, 'PROMOTION_ANNUAL_COMMISSION', 'TENANT_COMMISSION_CLEARING'),
            'commissionCost' => $sum($query, ['COMMISSION_EARN', 'PROMOTION_ANNUAL_COMMISSION'], 'TENANT_COMMISSION_CLEARING'), 'feeIncome' => $sum($query, ['CARD_ISSUE_FEE_SETTLE', 'WITHDRAWAL_SETTLE', 'ASSET_WITHDRAWAL_SETTLE', 'ASSET_EXCHANGE_IN'], 'TENANT_FEE_REVENUE')];
        $rows = (clone $daily)->where('p.delta', '>', 0)->whereIn('e.event_type', [
            'PROMOTION_ANNUAL_FEE', 'PROMOTION_FEE_REBATE', 'PROMOTION_ANNUAL_COMMISSION', 'ASSET_DEPOSIT', 'ASSET_WITHDRAWAL_SETTLE', 'ASSET_EXCHANGE_IN', 'WALLET_TOPUP_CREDIT', 'WITHDRAWAL_SETTLE', 'COMMISSION_EARN', 'COMMISSION_TRANSFER', 'SECURITY_DEPOSIT_FUND', 'SECURITY_DEPOSIT_REFUND',
            'CARD_ISSUE_FEE_SETTLE', 'CARD_INITIAL_LOAD_SETTLE', 'CARD_LOAD_SETTLE', 'CARD_RETURN_SETTLE', 'CARD_CANCEL_RETURN_SETTLE',
        ])->orderByDesc('e.posted_at')->orderBy('e.id')->orderBy('p.id')->offset(($page - 1) * 30)->limit(31)->get(['p.id', 'e.event_type', 'e.posted_at', 'p.delta', 'a.account_type']);

        return ['date' => $day->format('Y-m-d'), 'timezone' => $tenant->timezone,
            'totals' => $totals($daily), 'lifetimeTotals' => $totals($base),
            'rows' => $rows->take(30)->map(fn ($row) => ['id' => $row->id,
                'type' => in_array($row->event_type, ['WITHDRAWAL_SETTLE', 'ASSET_WITHDRAWAL_SETTLE'], true) && $row->account_type === 'TENANT_FEE_REVENUE' ? 'WITHDRAWAL_FEE_INCOME' : $row->event_type,
                'amount' => Money::of($row->delta, 'USDT')->amount(), 'occurredAt' => $row->posted_at])->all(),
            'page' => $page, 'hasMore' => $rows->count() > 30];
    }
}
