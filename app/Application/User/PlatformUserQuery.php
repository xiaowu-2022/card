<?php

namespace App\Application\User;

use App\Application\Promotion\ManualPromotion;
use App\Application\Promotion\OrdinaryMemberQuery;
use App\Application\Wallet\PlatformWalletQuery;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PlatformUserQuery
{
    public function paginate(?string $company, ?string $search, ?string $status, array $financialAccess = []): LengthAwarePaginator
    {
        $at = CarbonImmutable::now();
        // Platform-only aggregate. Profile ownership matches both company and user.
        $page = DB::table('users as u')->join('tenants as t', 't.id', '=', 'u.tenant_id')
            ->leftJoinSub(app(OrdinaryMemberQuery::class)->users($company, $at), 'ordinary', fn ($j) => $j->on('ordinary.user_id', '=', 'u.id')->on('ordinary.tenant_id', '=', 'u.tenant_id'))
            ->leftJoin('user_profiles as p', fn ($join) => $join->on('p.user_id', '=', 'u.id')->on('p.tenant_id', '=', 'u.tenant_id'))
            ->when($company, fn ($q) => $q->where('u.tenant_id', $company))
            ->when($status, fn ($q) => $q->where('u.status', $status))
            ->when($search, fn ($q) => $q->where(function ($q) use ($search): void {
                $pattern = '%'.addcslashes($search, '%_').'%';
                $q->where('u.account_id', 'like', $pattern)->orWhere('u.email', 'ilike', $pattern)
                    ->orWhere('p.display_name', 'ilike', $pattern);
            }))
            ->select(['u.id', 'u.tenant_id', 't.name as company_name', 'u.account_id', 'p.display_name', 'u.email', 'u.status', 'u.created_at', 'u.last_login_at'])
            ->selectRaw('ordinary.user_id IS NOT NULL AS ordinary_member')
            ->when($financialAccess['balances'] ?? false, fn ($q) => $q
                ->selectSub($this->balance('USER_AVAILABLE'), 'available_balance')
                ->selectSub($this->balance('USER_SECURITY_DEPOSIT'), 'security_deposit'))
            ->when($financialAccess['commission'] ?? false, fn ($q) => $q->selectSub($this->commissionIncome(), 'commission'))
            ->when($financialAccess['withdrawals'] ?? false, fn ($q) => $q->selectSub(
                DB::table('withdrawal_orders as wo')->whereColumn('wo.tenant_id', 'u.tenant_id')->whereColumn('wo.user_id', 'u.id')
                    ->where('wo.asset_code', 'USDT')->where('wo.status', 'SUCCEEDED')
                    ->selectRaw('COALESCE(SUM(wo.amount), 0)::text'), 'total_withdrawn'))
            ->orderByDesc('u.created_at')->orderBy('u.id')->paginate(20)->appends(request()->except(['kyc_company', 'kyc_user', 'kyc_application', 'kyc_page', 'funds_company', 'funds_user', 'funds_page', 'funds_asset', 'funds_event', 'funds_from', 'funds_to']));
        $wallets = ($financialAccess['balances'] ?? false) ? app(PlatformWalletQuery::class)->forUsers($page->getCollection()->pluck('id')->all()) : [];

        return $page->through(fn ($row): array => [
            'id' => $row->id, 'companyId' => $row->tenant_id, 'companyName' => $row->company_name,
            'accountId' => $row->account_id, 'displayName' => $row->display_name,
            'ordinaryMember' => (bool) $row->ordinary_member,
            'email' => $row->email, 'promotionRank' => app(ManualPromotion::class)->benefit($row->tenant_id, $row->id, $at)?->rank ?? 0, 'status' => $row->status,
            'createdAt' => $row->created_at, 'lastLoginAt' => $row->last_login_at,
        ] + (($financialAccess['balances'] ?? false) ? [
            'wallets' => $wallets[$row->id] ?? [],
            'availableBalance' => $this->decimal($row->available_balance), 'securityDeposit' => $this->decimal($row->security_deposit),
        ] : []) + (($financialAccess['commission'] ?? false) ? ['commission' => $this->decimal($row->commission)] : [])
                + (($financialAccess['withdrawals'] ?? false) ? ['totalWithdrawn' => $this->decimal($row->total_withdrawn)] : []));
    }

    private function balance(string $type): Builder
    {
        // Correlated subqueries avoid multiplying amounts when a user has multiple orders/accounts.
        return DB::table('ledger_accounts as la')->whereColumn('la.tenant_id', 'u.tenant_id')->whereColumn('la.user_id', 'u.id')
            ->where('la.asset_code', 'USDT')->where('la.account_type', $type)->selectRaw('COALESCE(SUM(la.balance), 0)::text');
    }

    private function commissionIncome(): Builder
    {
        return DB::table('ledger_postings as cp')->join('ledger_entries as ce', fn ($j) => $j->on('ce.id', '=', 'cp.ledger_entry_id')->on('ce.tenant_id', '=', 'cp.tenant_id'))
            ->join('ledger_accounts as ca', fn ($j) => $j->on('ca.id', '=', 'cp.ledger_account_id')->on('ca.tenant_id', '=', 'cp.tenant_id'))
            ->whereColumn('ca.tenant_id', 'u.tenant_id')->whereColumn('ca.user_id', 'u.id')->where('ca.asset_code', 'USDT')
            ->whereNotNull('ce.sealed_at')->whereIn('ce.event_type', ['COMMISSION_EARN', 'PROMOTION_ANNUAL_COMMISSION', 'MANUAL_COMMISSION'])->where(fn ($q) => $q->where('cp.delta', '>', 0)->orWhere('ce.event_type', 'MANUAL_COMMISSION'))
            ->selectRaw('COALESCE(SUM(cp.delta), 0)::text');
    }

    private function decimal(string $value): string
    {
        return (string) BigDecimal::of($value)->toScale(8);
    }
}
