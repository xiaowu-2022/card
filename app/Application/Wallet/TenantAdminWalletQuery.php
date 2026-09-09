<?php

namespace App\Application\Wallet;

use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;

final class TenantAdminWalletQuery
{
    /** @return array<string, mixed> */
    public function wallet(string $tenantId, string $userId): array
    {
        User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
        $wallet = Wallet::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->with('accounts')->first();
        if (! $wallet) {
            return ['wallet' => null];
        }
        $accounts = $wallet->accounts->keyBy(fn (LedgerAccount $account): string => $account->account_type->value);
        $amount = fn (LedgerAccountType $type): array => [
            'amount' => $accounts->get($type->value)?->balance ?? '0.00000000',
            'asset' => $wallet->asset_code,
        ];
        $hold = collect([LedgerAccountType::UserWithdrawalHold, LedgerAccountType::UserCardIssueHold, LedgerAccountType::UserCardFundingHold])
            ->reduce(fn (Money $sum, LedgerAccountType $type): Money => $sum->add(Money::of($accounts->get($type->value)?->balance ?? '0', $wallet->asset_code)), Money::of('0', $wallet->asset_code));

        return ['wallet' => [
            'id' => $wallet->id, 'status' => $wallet->status->value, 'asset' => $wallet->asset_code,
            'available' => $amount(LedgerAccountType::UserAvailable),
            'securityDeposit' => $amount(LedgerAccountType::UserSecurityDeposit),
            'holdTotal' => $hold->jsonSerialize(),
            'createdAt' => $wallet->created_at->toIso8601String(),
        ]];
    }

    /** @return array<string, mixed> */
    public function ledger(string $tenantId, string $userId): array
    {
        User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
        $entries = LedgerEntry::query()->where('ledger_entries.tenant_id', $tenantId)
            ->whereNotNull('ledger_entries.sealed_at')
            ->whereHas('postings.account', fn ($query) => $query->where('ledger_accounts.user_id', $userId))
            ->with(['postings' => fn ($query) => $query->whereHas('account', fn ($account) => $account->where('ledger_accounts.user_id', $userId))])
            ->latest('posted_at')->paginate(20)->through(fn (LedgerEntry $entry): array => [
                'id' => $entry->id,
                'reference' => $entry->reference_id ?? $entry->id,
                'eventType' => $entry->event_type,
                'asset' => $entry->asset_code,
                'delta' => $entry->postings->reduce(fn (Money $sum, $posting): Money => $sum->add(Money::of($posting->delta, $entry->asset_code)), Money::of('0', $entry->asset_code))->amount(),
                'postedAt' => $entry->posted_at->toIso8601String(),
            ]);

        return ['entries' => $entries];
    }
}
