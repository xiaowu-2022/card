<?php

namespace App\Application\User;

use Brick\Math\BigDecimal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PlatformUserQuery
{
    public function paginate(?string $company, ?string $search, ?string $status, array $financialAccess = []): LengthAwarePaginator
    {
        // Platform-only aggregate. Profile ownership matches both company and user.
        return DB::table('users as u')->join('tenants as t', 't.id', '=', 'u.tenant_id')
            ->leftJoin('user_profiles as p', fn ($join) => $join->on('p.user_id', '=', 'u.id')->on('p.tenant_id', '=', 'u.tenant_id'))
            ->when($company, fn ($q) => $q->where('u.tenant_id', $company))
            ->when($status, fn ($q) => $q->where('u.status', $status))
            ->when($search, fn ($q) => $q->where(function ($q) use ($search): void {
                $pattern = '%'.addcslashes($search, '%_').'%';
                $q->where('u.account_id', 'like', $pattern)->orWhere('u.email', 'ilike', $pattern)
                    ->orWhere('u.phone', 'like', $pattern)->orWhere('p.display_name', 'ilike', $pattern);
            }))
            ->select(['u.id', 'u.tenant_id', 't.name as company_name', 'u.account_id', 'p.display_name', 'u.email', 'u.phone', 'u.status', 'u.created_at', 'u.last_login_at'])
            ->when($financialAccess['balances'] ?? false, fn ($q) => $q
                ->selectSub($this->balance('USER_AVAILABLE'), 'available_balance')
                ->selectSub($this->balance('USER_SECURITY_DEPOSIT'), 'security_deposit'))
            ->when($financialAccess['commission'] ?? false, fn ($q) => $q->selectSub($this->balance('USER_COMMISSION'), 'commission'))
            ->when($financialAccess['withdrawals'] ?? false, fn ($q) => $q->selectSub(
                DB::table('withdrawal_orders as wo')->whereColumn('wo.tenant_id', 'u.tenant_id')->whereColumn('wo.user_id', 'u.id')
                    ->where('wo.asset_code', 'USDT')->where('wo.status', 'SUCCEEDED')
                    ->selectRaw('COALESCE(SUM(wo.amount), 0)::text'), 'total_withdrawn'))
            ->orderByDesc('u.created_at')->orderBy('u.id')->paginate(20)->withQueryString()
            ->through(fn ($row): array => [
                'id' => $row->id, 'companyId' => $row->tenant_id, 'companyName' => $row->company_name,
                'accountId' => $row->account_id, 'displayName' => $row->display_name,
                'email' => $row->email, 'phone' => $row->phone, 'status' => $row->status,
                'createdAt' => $row->created_at, 'lastLoginAt' => $row->last_login_at,
            ] + (($financialAccess['balances'] ?? false) ? [
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

    private function decimal(string $value): string
    {
        return (string) BigDecimal::of($value)->toScale(8);
    }
}
