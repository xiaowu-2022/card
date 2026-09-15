<?php

use App\Application\Promotion\PromotionMembershipAction;
use App\Application\SecurityDeposit\SecurityDepositFundingQuery;
use App\Application\Tenant\CreateTenantAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Services\WalletProvisioner;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed();
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->owner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
});

function legacyUnusedWallet($test)
{
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
    // Deliberate pre-upgrade fixture, before any account exists.
    $test->tenant->update(['default_asset' => 'USD']);
    $test->tenant->businessSettings()->update(['required_security_deposit_asset' => 'USD']);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');

    return app(WalletProvisioner::class)->provision($test->tenant->fresh(), $test->user, 'USD');
}

function normalizeUnusedWallets(): void
{
    // Flush fixture insert checks before migration DDL, as after a real fixture commit.
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
    (require database_path('migrations/2026_09_13_000400_normalize_unused_usd_wallets_to_usdt.php'))->up();
}

it('seeds only USDT wallets and settings while retaining real USD card products', function (): void {
    expect(config('tenancy.supported_assets'))->toBe(['USDT'])
        ->and(Tenant::query()->pluck('default_asset')->unique()->all())->toBe(['USDT'])
        ->and(DB::table('tenant_business_settings')->pluck('required_security_deposit_asset')->unique()->all())->toBe(['USDT'])
        ->and(DB::table('card_products')->pluck('card_currency')->unique()->all())->toBe(['USD']);
});

it('rejects additional currencies at both company HTTP and application boundaries', function (string $asset): void {
    Mail::fake();
    $data = ['name' => 'Single Currency', 'slug' => 'single', 'default_locale' => 'en', 'timezone' => 'UTC',
        'default_asset' => $asset, 'owner_email' => 'single@example.test'];
    $this->actingAs($this->owner, 'platform_admin')->post('http://admin.localhost/platform/tenants', $data)
        ->assertSessionHasErrors('default_asset');
    expect(fn () => app(CreateTenantAction::class)->execute($data, $this->owner))->toThrow(ValidationException::class)
        ->and(Tenant::query()->where('slug', 'single')->exists())->toBeFalse();
})->with(['USD', 'EUR', 'MYR', 'BTC']);

it('normalizes unused legacy metadata preserving IDs and zero balances and is idempotent', function (): void {
    $wallet = legacyUnusedWallet($this);
    $before = DB::table('ledger_accounts')->where('tenant_id', $this->tenant->id)->orderBy('id')->get(['id', 'balance'])->toJson();
    $invitation = app(PromotionMembershipAction::class)->companyInvitation($this->tenant->id)->invitation_code;
    normalizeUnusedWallets();
    normalizeUnusedWallets();
    expect($wallet->fresh()->asset_code)->toBe('USDT')
        ->and($this->tenant->fresh()->default_asset)->toBe('USDT')
        ->and($this->tenant->businessSettings()->value('required_security_deposit_asset'))->toBe('USDT')
        ->and(DB::table('ledger_accounts')->where('tenant_id', $this->tenant->id)->orderBy('id')->get(['id', 'balance'])->toJson())->toBe($before)
        ->and(DB::table('ledger_entries')->count())->toBe(0)
        ->and(DB::table('ledger_postings')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'UNUSED_WALLET_DENOMINATION_NORMALIZED')->count())->toBe(1)
        ->and(app(PromotionMembershipAction::class)->companyInvitation($this->tenant->id)->invitation_code)->toBe($invitation);
    // The migration never leaves a runtime asset-change escape hatch.
    expect(fn () => DB::transaction(fn () => DB::table('wallets')->where('tenant_id', $this->tenant->id)->where('id', $wallet->id)->update(['asset_code' => 'USD'])))
        ->toThrow(QueryException::class);
    $account = LedgerAccount::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    expect(fn () => DB::transaction(fn () => DB::table('ledger_accounts')->where('tenant_id', $this->tenant->id)->where('id', $account->id)->update(['asset_code' => 'USD'])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(function (): void {
        DB::table('tenants')->where('id', $this->tenant->id)->update(['default_asset' => 'USD']);
        DB::statement('SET CONSTRAINTS tenant_default_asset_stability IMMEDIATE');
    }))->toThrow(QueryException::class);
});

it('refuses a legacy wallet with history even when every balance is zero', function (): void {
    $wallet = legacyUnusedWallet($this);
    $available = LedgerAccount::query()->where('tenant_id', $this->tenant->id)->where('wallet_id', $wallet->id)->where('account_type', 'USER_AVAILABLE')->firstOrFail();
    $clearing = LedgerAccount::query()->where('tenant_id', $this->tenant->id)->where('account_type', 'TENANT_TOPUP_CLEARING')->firstOrFail();
    foreach (['1', '-1'] as $amount) {
        app(LedgerWriter::class)->post(new LedgerPostingPlan($this->tenant->id, 'USD', 'legacy-fixture:'.$amount, 'TEST_HISTORY', null, null, null, [
            new LedgerPostingInstruction($available->id, Money::of($amount, 'USD')),
            new LedgerPostingInstruction($clearing->id, Money::of($amount === '1' ? '-1' : '1', 'USD')),
        ]));
    }
    $before = DB::table('ledger_entries')->orderBy('id')->get()->toJson();
    expect(fn () => normalizeUnusedWallets())->toThrow(RuntimeException::class, 'ledger_entries history')
        ->and($wallet->fresh()->asset_code)->toBe('USD')
        ->and($this->tenant->fresh()->default_asset)->toBe('USD')
        ->and(DB::table('ledger_entries')->orderBy('id')->get()->toJson())->toBe($before)
        ->and(DB::table('audit_logs')->where('action', 'UNUSED_WALLET_DENOMINATION_NORMALIZED')->count())->toBe(0);
});

it('does not bypass KYC when a legacy wallet is normalized', function (): void {
    legacyUnusedWallet($this);
    normalizeUnusedWallets();
    expect(fn () => app(SecurityDepositFundingQuery::class)->preview($this->tenant->id, $this->user->id))
        ->toThrow(DomainException::class);
});
