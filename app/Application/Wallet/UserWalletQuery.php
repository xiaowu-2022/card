<?php

namespace App\Application\Wallet;

use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;

final readonly class UserWalletQuery
{
    public function __construct(private WalletEligibilityService $eligibility) {}

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $userId): array
    {
        $tenant = Tenant::query()->whereKey($tenantId)->with('businessSettings')->firstOrFail();
        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
        $eligibility = $this->eligibility->forUser($tenant, $user);
        $activity = $eligibility['wallet'] === null ? [] : LedgerEntry::query()
            ->where('ledger_entries.tenant_id', $tenantId)
            ->whereHas('postings.account', fn ($query) => $query->where('ledger_accounts.user_id', $userId))
            ->latest('posted_at')->limit(20)->get()->map(fn (LedgerEntry $entry): array => [
                'id' => $entry->id,
                'eventType' => $entry->event_type,
                'asset' => $entry->asset_code,
                'postedAt' => $entry->posted_at->toIso8601String(),
            ])->all();

        return ['eligibility' => $eligibility, 'activity' => $activity];
    }
}
