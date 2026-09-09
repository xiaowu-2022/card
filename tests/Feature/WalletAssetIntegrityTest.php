<?php

use App\Application\Tenant\UpdateTenantBusinessSettingsAction;
use App\Application\Wallet\WalletEligibilityService;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Services\WalletProvisioner;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->with('businessSettings')->firstOrFail();
    $this->user = User::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->actor = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $this->wallet = DB::transaction(fn () => app(WalletProvisioner::class)->provision($this->tenant, $this->user, 'USD'));
});

function updateDepositRequirement($test, string $amount, string $asset = 'USD'): void
{
    app(UpdateTenantBusinessSettingsAction::class)->execute($test->tenant, [
        'required_security_deposit_amount' => $amount,
        'required_security_deposit_asset' => $asset,
        'allow_wallet_topup' => false,
        'allow_withdrawal' => false,
    ], $test->actor);
    $test->tenant->unsetRelation('businessSettings');
}

it('freezes tenant default asset after financial account creation', function (): void {
    expect(function (): void {
        DB::transaction(function (): void {
            DB::table('tenants')->where('id', $this->tenant->id)->update(['default_asset' => 'EUR']);
            DB::statement('SET CONSTRAINTS tenant_default_asset_stability IMMEDIATE');
        });
    })->toThrow(QueryException::class);

    expect($this->tenant->fresh()->default_asset)->toBe('USD');
});

it('requires the deposit asset to match the tenant default in application and database paths', function (): void {
    try {
        updateDepositRequirement($this, '50', 'eur');
        $this->fail('Expected deposit asset mismatch.');
    } catch (DomainException $exception) {
        expect($exception->errorCode)->toBe('SECURITY_DEPOSIT_ASSET_MISMATCH');
    }

    expect(function (): void {
        DB::transaction(function (): void {
            DB::table('tenant_business_settings')->where('tenant_id', $this->tenant->id)->update(['required_security_deposit_asset' => 'EUR']);
            DB::statement('SET CONSTRAINTS tenant_deposit_asset_alignment IMMEDIATE');
        });
    })->toThrow(QueryException::class);
    expect($this->tenant->businessSettings()->value('required_security_deposit_asset'))->toBe('USD');
});

it('changes only the required deposit amount without creating financial history', function (): void {
    updateDepositRequirement($this, '75.00000000');

    expect($this->tenant->businessSettings()->value('required_security_deposit_amount'))->toBe('75.00000000')
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and(LedgerAccount::query()->where('wallet_id', $this->wallet->id)->pluck('balance')->unique()->all())->toBe(['0.00000000']);
});

it('calculates deposit qualification in decimal and clamps overfunded remaining to zero', function (string $current, string $remaining, bool $satisfied): void {
    updateDepositRequirement($this, '50');
    $deposit = LedgerAccount::query()->where('wallet_id', $this->wallet->id)->where('account_type', LedgerAccountType::UserSecurityDeposit)->firstOrFail();
    $clearing = LedgerAccount::query()->where('tenant_id', $this->tenant->id)->whereNull('wallet_id')->where('account_type', LedgerAccountType::TenantTopupClearing)->firstOrFail();
    app(LedgerWriter::class)->post(new LedgerPostingPlan(
        $this->tenant->id, 'USD', 'audit:deposit:'.$current, 'CORE_INTEGRITY_TEST', 'CORE_TEST', (string) Str::uuid(), null,
        [
            new LedgerPostingInstruction($clearing->id, Money::of('-'.$current, 'USD')),
            new LedgerPostingInstruction($deposit->id, Money::of($current, 'USD')),
        ],
    ));

    $result = app(WalletEligibilityService::class)->forUser($this->tenant->fresh()->load('businessSettings'), $this->user);
    expect($result['depositCurrent']['amount'])->toBe($current.'.00000000')
        ->and($result['depositRemaining']['amount'])->toBe($remaining)
        ->and($result['depositSatisfied'])->toBe($satisfied);
})->with([
    ['20', '30.00000000', false],
    ['60', '0.00000000', true],
]);

it('fails eligibility closed without cross-asset arithmetic on corrupted legacy state', function (): void {
    $settings = $this->tenant->businessSettings;
    $settings->setAttribute('required_security_deposit_asset', 'EUR');
    $this->tenant->setRelation('businessSettings', $settings);

    $result = app(WalletEligibilityService::class)->forUser($this->tenant, $this->user);
    expect($result['depositSatisfied'])->toBeFalse()
        ->and($result['canUseCardService'])->toBeFalse()
        ->and($result['reasonCodes'])->toContain('SECURITY_DEPOSIT_ASSET_MISMATCH')
        ->and($result['depositCurrent'])->toBe(['amount' => '0.00000000', 'asset' => 'EUR']);
});

it('fails eligibility closed when a corrupted tenant default differs from its wallet', function (): void {
    $this->tenant->setAttribute('default_asset', 'EUR');

    $result = app(WalletEligibilityService::class)->forUser($this->tenant, $this->user);
    expect($result['depositSatisfied'])->toBeFalse()
        ->and($result['reasonCodes'])->toContain('SECURITY_DEPOSIT_ASSET_MISMATCH');
});
