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
            ->leftJoin('manual_commission_adjustments as m', fn ($j) => $j->on('m.ledger_entry_id', '=', 'e.id')->on('m.tenant_id', '=', 'e.tenant_id'))
            ->leftJoin('commission_adjustment_classifications as c', fn ($j) => $j->on('c.adjustment_id', '=', 'm.id')->on('c.tenant_id', '=', 'm.tenant_id'))
            ->where('e.tenant_id', $tenantId)->whereNotNull('e.sealed_at')->where('e.asset_code', 'USDT');
        $daily = (clone $base)->where('e.posted_at', '>=', $day->utc()->toIso8601String())->where('e.posted_at', '<', $day->addDay()->utc()->toIso8601String());
        $sum = fn ($query, $type, $account) => (string) ((clone $query)->whereIn('e.event_type', (array) $type)->where('a.account_type', $account)->selectRaw("CASE WHEN COUNT(*) = 0 THEN '0' ELSE SUM(ABS(p.delta))::numeric(20,8)::text END AS amount")->value('amount'));
        $commission = fn ($query, $events) => (string) (clone $query)->whereIn('e.event_type', $events)->where('a.account_type', 'TENANT_COMMISSION_CLEARING')->selectRaw("CASE WHEN COUNT(*)=0 THEN '0' ELSE (-SUM(p.delta))::numeric(20,8)::text END AS amount")->value('amount');
        $totals = fn ($query) => ['topups' => $sum($query, ['WALLET_TOPUP_CREDIT', 'ASSET_DEPOSIT'], 'USER_AVAILABLE'),
            'withdrawals' => $sum($query, ['WITHDRAWAL_SETTLE', 'ASSET_WITHDRAWAL_SETTLE'], 'TENANT_WITHDRAWAL_CLEARING'),
            'activationCommissions' => Money::of($commission($query, ['COMMISSION_EARN']), 'USDT')->add(Money::of($commission((clone $query)->where('c.kind', 'activation'), ['MANUAL_COMMISSION']), 'USDT'))->amount(),
            'annualFees' => $sum($query, 'PROMOTION_ANNUAL_FEE', 'TENANT_PROMOTION_FEE_REVENUE'), 'annualRebates' => $sum($query, 'PROMOTION_FEE_REBATE', 'USER_AVAILABLE'), 'annualCommissions' => Money::of($commission($query, ['PROMOTION_ANNUAL_COMMISSION']), 'USDT')->add(Money::of($commission((clone $query)->where('c.kind', 'annual'), ['MANUAL_COMMISSION']), 'USDT'))->amount(),
            'legacyCommissions' => $commission((clone $query)->where('c.kind', 'legacy'), ['MANUAL_COMMISSION']), 'unclassifiedCommissions' => $commission((clone $query)->whereNull('c.kind'), ['MANUAL_COMMISSION']), 'commissionCost' => $commission($query, ['COMMISSION_EARN', 'PROMOTION_ANNUAL_COMMISSION', 'MANUAL_COMMISSION']), 'feeIncome' => $sum($query, ['CARD_ISSUE_FEE_SETTLE', 'WITHDRAWAL_SETTLE', 'ASSET_WITHDRAWAL_SETTLE', 'ASSET_EXCHANGE_IN'], 'TENANT_FEE_REVENUE')];
        $rows = (clone $daily)->where(fn ($q) => $q->where(fn ($q) => $q->where('e.event_type', '<>', 'MANUAL_COMMISSION')->where('p.delta', '>', 0))->orWhere(fn ($q) => $q->where('e.event_type', 'MANUAL_COMMISSION')->where('a.account_type', 'USER_AVAILABLE')))->whereIn('e.event_type', [
            'PROMOTION_ANNUAL_FEE', 'PROMOTION_FEE_REBATE', 'PROMOTION_ANNUAL_COMMISSION', 'ASSET_DEPOSIT', 'ASSET_WITHDRAWAL_SETTLE', 'ASSET_EXCHANGE_IN', 'WALLET_TOPUP_CREDIT', 'WITHDRAWAL_SETTLE', 'COMMISSION_EARN', 'MANUAL_COMMISSION', 'COMMISSION_TRANSFER', 'SECURITY_DEPOSIT_FUND', 'SECURITY_DEPOSIT_REFUND',
            'CARD_ISSUE_FEE_SETTLE', 'CARD_INITIAL_LOAD_SETTLE', 'CARD_LOAD_SETTLE', 'CARD_RETURN_SETTLE', 'CARD_CANCEL_RETURN_SETTLE',
        ])->orderByDesc('e.posted_at')->orderBy('e.id')->orderBy('p.id')->offset(($page - 1) * 30)->limit(31)->get(['p.id', 'c.kind', 'e.event_type', 'e.posted_at', 'p.delta', 'a.account_type']);

        return ['date' => $day->format('Y-m-d'), 'timezone' => $tenant->timezone,
            'totals' => $totals($daily), 'lifetimeTotals' => $totals($base),
            'rows' => $rows->take(30)->map(fn ($row) => ['id' => $row->id,
                'type' => $row->event_type === 'MANUAL_COMMISSION' ? (['activation' => 'COMMISSION_EARN', 'annual' => 'PROMOTION_ANNUAL_COMMISSION', 'legacy' => 'LEGACY_COMMISSION'][$row->kind] ?? 'COMMISSION') : (in_array($row->event_type, ['WITHDRAWAL_SETTLE', 'ASSET_WITHDRAWAL_SETTLE'], true) && $row->account_type === 'TENANT_FEE_REVENUE' ? 'WITHDRAWAL_FEE_INCOME' : $row->event_type),
                'amount' => Money::of($row->delta, 'USDT')->amount(), 'occurredAt' => $row->posted_at])->all(),
            'page' => $page, 'hasMore' => $rows->count() > 30];
    }
}
