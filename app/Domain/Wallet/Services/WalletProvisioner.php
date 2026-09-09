<?php

namespace App\Domain\Wallet\Services;

use App\Domain\Ledger\Enums\LedgerAccountStatus;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Enums\WalletStatus;
use App\Domain\Wallet\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WalletProvisioner
{
    /** Must run inside the activation transaction after Tenant and User locks are held. */
    public function provision(Tenant $tenant, User $user, string $assetCode): Wallet
    {
        $wallet = Wallet::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('asset_code', $assetCode)->first();
        if ($wallet) {
            return $wallet;
        }

        $wallet = Wallet::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'asset_code' => $assetCode,
            'status' => WalletStatus::Active,
        ]);

        $now = now();
        DB::table('ledger_accounts')->insert(array_map(fn (LedgerAccountType $type): array => [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'account_type' => $type->value,
            'asset_code' => $assetCode,
            'balance' => '0.00000000',
            'status' => LedgerAccountStatus::Active->value,
            'created_at' => $now,
            'updated_at' => $now,
        ], LedgerAccountType::userTypes()));

        foreach (LedgerAccountType::tenantTypes() as $type) {
            DB::table('ledger_accounts')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'wallet_id' => null,
                'user_id' => null,
                'account_type' => $type->value,
                'asset_code' => $assetCode,
                'balance' => '0.00000000',
                'status' => LedgerAccountStatus::Active->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $wallet;
    }
}
