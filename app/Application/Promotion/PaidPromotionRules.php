<?php

namespace App\Application\Promotion;

use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Ledger\Enums\LedgerAccountStatus;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use App\Support\Errors\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PaidPromotionRules
{
    public function cycle(string $tenant, string $user, ?CarbonImmutable $at = null): ?object
    {
        $at ??= CarbonImmutable::now();

        return DB::table('paid_promotion_cycles')->where('tenant_id', $tenant)->where('user_id', $user)
            ->where('starts_at', '<=', $at)->where('ends_at', '>', $at)->orderByDesc('starts_at')->first();
    }

    public function operational(string $tenantId, string $userId): Tenant
    {
        $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail();
        $wallet = Wallet::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('asset_code', 'USDT')->first();
        if ($tenant->status->value !== 'ACTIVE' || $user->status->value !== 'ACTIVE' || $wallet?->status->value !== 'ACTIVE'
            || app(KycStatusService::class)->forUser($tenantId, $userId)->value !== 'APPROVED') {
            throw new DomainException('PROMOTION_ACCESS_UNAVAILABLE', 'An active verified wallet is required.', 403);
        }

        return $tenant;
    }

    public function available(string $tenant, string $user): LedgerAccount
    {
        return LedgerAccount::query()->where('tenant_id', $tenant)->where('user_id', $user)->where('asset_code', 'USDT')->where('account_type', 'USER_AVAILABLE')->firstOrFail();
    }

    public function revenue(string $tenant): LedgerAccount
    {
        $key = ['tenant_id' => $tenant, 'user_id' => null, 'wallet_id' => null, 'asset_code' => 'USDT', 'account_type' => LedgerAccountType::TenantPromotionFeeRevenue];

        return LedgerAccount::query()->where($key)->first() ?? LedgerAccount::query()->forceCreate($key + ['status' => LedgerAccountStatus::Active])->refresh();
    }

    public function platform(AdminUser $actor, string $permission): void
    {
        $actor = $actor->fresh();
        if (! $actor || $actor->status->value !== 'ACTIVE' || ! app(AuthorizationService::class)->allows($actor, ScopeType::Platform, null, $permission)) {
            abort(403);
        }
    }

    public function requestId(string $id): void
    {
        if (! Str::isUuid($id)) {
            throw new DomainException('PROMOTION_REQUEST_INVALID', 'A valid request identifier is required.');
        }
    }

    /** Caller holds Tenant before any User/Ledger locks. Reads never create membership. */
    public function ancestors(string $tenant, string $source): array
    {
        return DB::select(<<<'SQL'
          WITH RECURSIVE chain AS (
            SELECT p.user_id,p.inviter_id,1 AS depth FROM promotion_members m JOIN promotion_members p ON p.id=m.inviter_id AND p.tenant_id=m.tenant_id WHERE m.tenant_id=? AND m.user_id=?
            UNION ALL SELECT p.user_id,p.inviter_id,c.depth+1 FROM promotion_members p JOIN chain c ON c.inviter_id=p.id WHERE p.tenant_id=?
          ) SELECT * FROM chain ORDER BY depth
          SQL, [$tenant, $source, $tenant]);
    }
}
