<?php

namespace App\Application\Wallet;

use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Wallet\Models\Wallet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class PlatformWalletQuery
{
    /** Bounded by the current user page; reads never create missing wallets. */
    public function forUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return Wallet::query()->select('wallets.*')
            ->join('users as owner', fn ($join) => $join->on('owner.id', '=', 'wallets.user_id')->on('owner.tenant_id', '=', 'wallets.tenant_id'))
            ->whereIn('wallets.user_id', $userIds)->with('accounts')->orderByRaw("CASE wallets.asset_code WHEN 'USDT' THEN 0 WHEN 'USDC' THEN 1 WHEN 'ETH' THEN 2 WHEN 'BTC' THEN 3 ELSE 4 END")
            ->orderBy('wallets.id')->get()->groupBy('user_id')->map(fn ($wallets) => $wallets->map(fn (Wallet $wallet) => [
                'id' => $wallet->id, 'asset' => $wallet->asset_code, 'status' => $wallet->status->value,
                ...$this->balances($wallet),
            ])->values()->all())->all();
    }

    private function balances(Wallet $wallet): array
    {
        $accounts = $wallet->accounts->where('tenant_id', $wallet->tenant_id)->where('user_id', $wallet->user_id)
            ->where('asset_code', $wallet->asset_code)->keyBy(fn (LedgerAccount $account): string => $account->account_type->value);
        $balance = fn (LedgerAccountType $type): string => Money::of($accounts->get($type->value)?->balance ?? '0', $wallet->asset_code)->amount();
        $held = Money::of('0', $wallet->asset_code);
        foreach ([LedgerAccountType::UserWithdrawalHold, LedgerAccountType::UserCardIssueHold, LedgerAccountType::UserCardFundingHold] as $type) {
            $held = $held->add(Money::of($balance($type), $wallet->asset_code));
        }

        return ['available' => $balance(LedgerAccountType::UserAvailable), 'securityDeposit' => $balance(LedgerAccountType::UserSecurityDeposit), 'held' => $held->amount()];
    }

    public function paginate(?string $tenantId, ?string $search): LengthAwarePaginator
    {
        return Wallet::query()->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->with([
                'tenant:id,name',
                'user' => fn ($query) => $query->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->select('id', 'tenant_id', 'account_id', 'email', 'phone'),
                'accounts' => fn ($query) => $query->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId)),
            ])
            ->when($search, fn ($query) => $query->whereHas('user', fn ($user) => $user
                ->whereColumn('users.tenant_id', 'wallets.tenant_id')->where(function ($query) use ($search): void {
                    $pattern = '%'.addcslashes($search, '%_').'%';
                    $query->where('account_id', 'like', $pattern)->orWhere('email', 'ilike', $pattern)->orWhere('phone', 'like', $pattern);
                })))
            ->latest('created_at')->orderBy('id')->paginate(20)->withQueryString()
            ->through(function (Wallet $wallet): array {
                return [
                    'id' => $wallet->id,
                    'companyId' => $wallet->tenant_id,
                    'companyName' => $wallet->tenant->name,
                    'accountId' => $wallet->user?->account_id,
                    'contact' => $wallet->user?->email ?? $wallet->user?->phone,
                    'status' => $wallet->status->value,
                    'asset' => $wallet->asset_code,
                    ...$this->balances($wallet),
                ];
            });
    }
}
