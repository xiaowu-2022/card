<?php

use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Services\WalletProvisioner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    legacyUsdAccountingFixtures();
    $this->tenantA = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $this->userA = User::query()->where('tenant_id', $this->tenantA->id)->firstOrFail();
    $this->userB = User::query()->where('tenant_id', $this->tenantB->id)->firstOrFail();
    $this->wallet = DB::transaction(fn () => app(WalletProvisioner::class)->provision($this->tenantA, $this->userA, 'USD'));
});

it('enforces wallet tenant user asset and uniqueness constraints', function (): void {
    expect(fn () => DB::transaction(fn () => Wallet::query()->create(['tenant_id' => $this->tenantA->id, 'user_id' => $this->userB->id, 'asset_code' => 'USD', 'status' => 'ACTIVE'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => Wallet::query()->create(['tenant_id' => $this->tenantA->id, 'user_id' => $this->userA->id, 'asset_code' => 'USD', 'status' => 'ACTIVE'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => Wallet::query()->create(['tenant_id' => $this->tenantA->id, 'user_id' => $this->userA->id, 'asset_code' => 'usd', 'status' => 'ACTIVE'])))->toThrow(QueryException::class);
});

it('enforces user and tenant account ownership and fixed negative policy', function (): void {
    $base = ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantA->id, 'asset_code' => 'USD', 'balance' => '0.00000000', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()];
    expect(fn () => DB::transaction(fn () => DB::table('ledger_accounts')->insert($base + ['wallet_id' => null, 'user_id' => null, 'account_type' => 'USER_AVAILABLE'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('ledger_accounts')->insert([...$base, 'id' => (string) Str::uuid(), 'wallet_id' => $this->wallet->id, 'user_id' => $this->userA->id, 'account_type' => 'TENANT_TOPUP_CLEARING'])))->toThrow(QueryException::class);

    $available = LedgerAccount::query()->where('wallet_id', $this->wallet->id)->where('account_type', LedgerAccountType::UserAvailable)->firstOrFail();
    $fee = LedgerAccount::query()->where('tenant_id', $this->tenantA->id)->where('account_type', LedgerAccountType::TenantFeeRevenue)->firstOrFail();
    $clearing = LedgerAccount::query()->where('tenant_id', $this->tenantA->id)->where('account_type', LedgerAccountType::TenantTopupClearing)->firstOrFail();
    expect(fn () => DB::transaction(fn () => DB::table('ledger_accounts')->where('id', $available->id)->update(['balance' => '-0.00000001'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('ledger_accounts')->where('id', $fee->id)->update(['balance' => '-0.00000001'])))->toThrow(QueryException::class);
    DB::table('ledger_accounts')->where('id', $clearing->id)->update(['balance' => '-0.00000001']);
    expect($clearing->fresh()->balance)->toBe('-0.00000001');
});

it('rejects zero postings and mismatched posting tenant or asset at the database boundary', function (string $case): void {
    $entryId = (string) Str::uuid();
    DB::table('ledger_entries')->insert(['id' => $entryId, 'tenant_id' => $this->tenantA->id, 'asset_code' => 'USD', 'event_key' => 'db:constraint:'.$case, 'event_hash' => hash('sha256', $case), 'event_type' => 'CORE_TEST_TRANSFER', 'reference_type' => null, 'reference_id' => null, 'reversal_of_entry_id' => null, 'posted_at' => now(), 'created_at' => now()]);
    $available = LedgerAccount::query()->where('wallet_id', $this->wallet->id)->where('account_type', LedgerAccountType::UserAvailable)->firstOrFail();
    $row = ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantA->id, 'asset_code' => 'USD', 'ledger_entry_id' => $entryId, 'ledger_account_id' => $available->id, 'delta' => '1.00000000', 'created_at' => now()];
    if ($case === 'zero') {
        $row['delta'] = '0.00000000';
    } elseif ($case === 'tenant') {
        $row['tenant_id'] = $this->tenantB->id;
    } else {
        $row['asset_code'] = 'USDT';
    }
    expect(fn () => DB::transaction(fn () => DB::table('ledger_postings')->insert($row)))->toThrow(QueryException::class);
})->with(['zero', 'tenant', 'asset']);
