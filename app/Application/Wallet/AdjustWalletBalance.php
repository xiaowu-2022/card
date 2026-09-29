<?php

namespace App\Application\Wallet;

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Enums\WalletStatus;
use App\Domain\Wallet\Models\Wallet;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AdjustWalletBalance
{
    public function execute(string $tenant, string $user, AdminUser $actor, array $data): object
    {
        $asset = $data['asset'];
        $reason = trim($data['reason']);
        if (! in_array($asset, ['USDT', 'USDC', 'ETH', 'BTC'], true)
            || ! in_array($data['direction'], ['INCREASE', 'DECREASE'], true)
            || ! Str::isUuid($data['request_id']) || $reason === '' || mb_strlen($reason) > 500
            || ! preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,'.Money::scale($asset).'})?$/D', $data['amount'])) {
            throw new DomainException('WALLET_ADJUSTMENT_INVALID', 'Enter a positive amount within the currency precision and an adjustment reason.');
        }
        $amount = Money::of($data['amount'], $asset);
        if (! $amount->isPositive()) {
            throw new DomainException('WALLET_ADJUSTMENT_INVALID', 'Enter a positive adjustment amount.');
        }

        return DB::transaction(function () use ($tenant, $user, $actor, $data, $asset, $reason, $amount) {
            $company = Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $account = User::where('tenant_id', $tenant)->whereKey($user)->lockForUpdate()->firstOrFail();
            $actor->refresh();
            $auth = app(AuthorizationService::class);
            abort_unless($actor->status === AdminUserStatus::Active && $auth->allows($actor, ScopeType::Platform, null, 'wallet.adjust')
                && $auth->allows($actor, ScopeType::Platform, null, 'users.read') && $auth->allows($actor, ScopeType::Platform, null, 'wallet.read'), 403);

            $old = DB::table('wallet_adjustments')->where('tenant_id', $tenant)->where('user_id', $user)->where('request_id', $data['request_id'])->first();
            if ($old) {
                if ($old->actor_id !== $actor->id || $old->asset_code !== $asset || $old->direction !== $data['direction'] || $old->reason !== $reason || Money::of($old->amount, $asset)->compare($amount) !== 0) {
                    throw new DomainException('WALLET_ADJUSTMENT_CONFLICT', 'This request was already used with different details.', 409);
                }

                return $old;
            }
            $wallet = Wallet::where('tenant_id', $tenant)->where('user_id', $user)->where('asset_code', $asset)->lockForUpdate()->first();
            if ($company->status !== TenantStatus::Active || $account->status !== UserStatus::Active || $wallet?->status !== WalletStatus::Active) {
                throw new DomainException('WALLET_ADJUSTMENT_UNAVAILABLE', 'An active account and an existing active wallet are required.');
            }
            $available = LedgerAccount::where('tenant_id', $tenant)->where('user_id', $user)->where('wallet_id', $wallet->id)->where('asset_code', $asset)->where('account_type', 'USER_AVAILABLE')->lockForUpdate()->firstOrFail();
            $before = Money::of($available->balance, $asset);
            $delta = Money::of(($data['direction'] === 'DECREASE' ? '-' : '').$amount->amount(), $asset);
            try {
                $after = $before->add($delta);
            } catch (\InvalidArgumentException) {
                throw new DomainException('WALLET_ADJUSTMENT_INVALID', 'The adjustment exceeds the supported balance range.');
            }
            if ($after->isNegative()) {
                throw new DomainException('WALLET_ADJUSTMENT_INSUFFICIENT', 'The adjustment exceeds the available balance.');
            }
            DB::table('ledger_accounts')->insertOrIgnore(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'wallet_id' => null, 'user_id' => null, 'account_type' => 'TENANT_ADJUSTMENT_CLEARING', 'asset_code' => $asset, 'balance' => '0', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
            $clearing = LedgerAccount::where('tenant_id', $tenant)->where('asset_code', $asset)->where('account_type', 'TENANT_ADJUSTMENT_CLEARING')->firstOrFail();
            $id = (string) Str::uuid();
            $entry = app(LedgerWriter::class)->post(new LedgerPostingPlan($tenant, $asset, 'wallet_adjustment:'.$id, 'WALLET_ADJUSTMENT', 'WALLET_ADJUSTMENT', $id, null, [
                new LedgerPostingInstruction($available->id, $delta),
                new LedgerPostingInstruction($clearing->id, Money::of('0', $asset)->subtract($delta)),
            ]));
            DB::table('wallet_adjustments')->insert(['id' => $id, 'tenant_id' => $tenant, 'user_id' => $user, 'wallet_id' => $wallet->id, 'actor_id' => $actor->id, 'actor_name' => $actor->name,
                'request_id' => $data['request_id'], 'asset_code' => $asset, 'direction' => $data['direction'], 'amount' => $amount->amount(), 'balance_before' => $before->amount(), 'balance_after' => $after->amount(), 'reason' => $reason, 'ledger_entry_id' => $entry->id, 'created_at' => now()]);
            app(AuditLogger::class)->record($tenant, 'ADMIN', $actor->id, 'WALLET_ADJUSTMENT', 'wallet_adjustment', $id, ['balance' => $before->amount()], ['balance' => $after->amount(), 'asset' => $asset, 'direction' => $data['direction']]);

            return DB::table('wallet_adjustments')->where('id', $id)->first();
        }, 3);
    }
}
