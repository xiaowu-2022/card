<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\SecurityDeposit\FundSecurityDepositAction;
use App\Application\Tenant\UpdateTenantBusinessSettingsAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Application\Wallet\UserWalletQuery;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use App\Support\Errors\DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    legacyUsdAccountingFixtures();
    Storage::fake('private');
    Queue::fake();
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->owner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
});

function phaseSixWallet($test, string $required = '50.00000000', string $available = '100.00000000'): Wallet
{
    app(UpdateTenantBusinessSettingsAction::class)->execute($test->tenant, [
        'required_security_deposit_amount' => $required,
        'required_security_deposit_asset' => 'USD',
        'allow_wallet_topup' => true,
        'allow_withdrawal' => false,
    ], AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail());
    $application = app(SubmitKycApplicationAction::class)->execute(
        $test->tenant, $test->user, 'MY', 'DEPOSIT-'.$test->user->id,
        kycTestImage('deposit-front.png'), kycTestImage('deposit-back.png'),
    );
    app(ApproveKycAction::class)->execute($test->tenant->id, $application->id, $test->owner);
    $wallet = app(ActivateUserWalletAction::class)->execute($test->tenant->id, $test->user->id)->wallet;
    if ($available !== '0.00000000') {
        $availableAccount = LedgerAccount::query()->where('wallet_id', $wallet->id)->where('account_type', LedgerAccountType::UserAvailable->value)->firstOrFail();
        $clearing = LedgerAccount::query()->where('tenant_id', $test->tenant->id)->whereNull('wallet_id')->where('account_type', LedgerAccountType::TenantTopupClearing->value)->firstOrFail();
        app(LedgerWriter::class)->post(new LedgerPostingPlan(
            $test->tenant->id, 'USD', 'security-deposit-test-credit:'.Str::uuid(), 'TEST_WALLET_CREDIT', null, null, null,
            [new LedgerPostingInstruction($clearing->id, Money::of('-'.$available, 'USD')), new LedgerPostingInstruction($availableAccount->id, Money::of($available, 'USD'))],
        ));
    }

    return $wallet;
}

function phaseSixFund($test, string $expected = '50.00000000', ?string $requestId = null)
{
    return app(FundSecurityDepositAction::class)->execute(
        $test->tenant->id, $test->user->id, $requestId ?? (string) Str::uuid(), $expected,
    );
}

it('funds the exact remaining amount through one immutable ledger entry', function (): void {
    $wallet = phaseSixWallet($this, '50', '120');
    $available = LedgerAccount::query()->where('wallet_id', $wallet->id)->where('account_type', LedgerAccountType::UserAvailable->value)->firstOrFail();
    $deposit = LedgerAccount::query()->where('wallet_id', $wallet->id)->where('account_type', LedgerAccountType::UserSecurityDeposit->value)->firstOrFail();
    app(LedgerWriter::class)->post(new LedgerPostingPlan(
        $this->tenant->id, 'USD', 'security-deposit-test:existing', 'TEST_EXISTING_DEPOSIT', null, null, null,
        [new LedgerPostingInstruction($available->id, Money::of('-20', 'USD')), new LedgerPostingInstruction($deposit->id, Money::of('20', 'USD'))],
    ));
    phaseSixFund($this, '30');
    $entry = LedgerEntry::query()->where('event_type', 'SECURITY_DEPOSIT_FUND')->firstOrFail();

    expect($available->fresh()->balance)->toBe('70.00000000')
        ->and($deposit->fresh()->balance)->toBe('50.00000000')
        ->and($entry->postings()->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'SECURITY_DEPOSIT_FUNDED')->count())->toBe(1);
});

it('funds only a later requirement increase and never refunds a decrease', function (): void {
    $wallet = phaseSixWallet($this);
    phaseSixFund($this);
    app(UpdateTenantBusinessSettingsAction::class)->execute($this->tenant, [
        'required_security_deposit_amount' => '75', 'required_security_deposit_asset' => 'USD', 'allow_wallet_topup' => true, 'allow_withdrawal' => false,
    ], AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail());
    phaseSixFund($this, '25');
    app(UpdateTenantBusinessSettingsAction::class)->execute($this->tenant, [
        'required_security_deposit_amount' => '20', 'required_security_deposit_asset' => 'USD', 'allow_wallet_topup' => true, 'allow_withdrawal' => false,
    ], AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail());

    expect(LedgerAccount::query()->where('wallet_id', $wallet->id)->where('account_type', LedgerAccountType::UserSecurityDeposit->value)->value('balance'))->toBe('75.00000000')
        ->and(LedgerEntry::query()->where('event_type', 'SECURITY_DEPOSIT_FUND')->count())->toBe(2)
        ->and(fn () => phaseSixFund($this, '0'))->toThrow(DomainException::class);
});

it('rejects insufficient available balance atomically', function (): void {
    $wallet = phaseSixWallet($this, '50', '49.99999999');
    expect(fn () => phaseSixFund($this))->toThrow(DomainException::class);
    expect(LedgerAccount::query()->where('wallet_id', $wallet->id)->where('account_type', LedgerAccountType::UserAvailable->value)->value('balance'))->toBe('49.99999999')
        ->and(LedgerAccount::query()->where('wallet_id', $wallet->id)->where('account_type', LedgerAccountType::UserSecurityDeposit->value)->value('balance'))->toBe('0.00000000')
        ->and(LedgerEntry::query()->where('event_type', 'SECURITY_DEPOSIT_FUND')->count())->toBe(0);
});

it('returns the original result for a repeated request without a second transfer', function (): void {
    phaseSixWallet($this);
    $requestId = (string) Str::uuid();
    $first = phaseSixFund($this, '50', $requestId);
    $again = phaseSixFund($this, '50', $requestId);

    expect($again->ledgerEntryId)->toBe($first->ledgerEntryId)->and($again->replayed)->toBeTrue()
        ->and(LedgerEntry::query()->where('event_type', 'SECURITY_DEPOSIT_FUND')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'SECURITY_DEPOSIT_FUNDED')->count())->toBe(1);
});

it('rejects a stale expected amount and client supplied financial authority', function (): void {
    phaseSixWallet($this);
    expect(fn () => phaseSixFund($this, '30'))->toThrow(DomainException::class, 'The security deposit amount changed');
    $this->actingAs($this->user, 'tenant_user')->post('http://a.localhost/security-deposit/fund', [
        'request_id' => (string) Str::uuid(), 'expected_remaining' => '50', 'amount' => '1', 'wallet_id' => (string) Str::uuid(),
    ])->assertSessionHasErrors(['amount', 'wallet_id']);
    expect(LedgerEntry::query()->where('event_type', 'SECURITY_DEPOSIT_FUND')->count())->toBe(0);
});

it('enforces every lifecycle and KYC eligibility rule', function (string $case): void {
    if ($case === 'kyc') {
        app(UpdateTenantBusinessSettingsAction::class)->execute($this->tenant, [
            'required_security_deposit_amount' => '50', 'required_security_deposit_asset' => 'USD', 'allow_wallet_topup' => true, 'allow_withdrawal' => false,
        ], AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail());
        Wallet::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'asset_code' => 'USD', 'status' => 'ACTIVE']);
    } else {
        $wallet = phaseSixWallet($this);
        match ($case) {
            'tenant' => $this->tenant->update(['status' => TenantStatus::Suspended, 'suspended_at' => now()]),
            'user' => $this->user->update(['status' => UserStatus::Suspended]),
            'wallet' => $wallet->update(['status' => 'SUSPENDED']),
        };
    }
    expect(fn () => phaseSixFund($this))->toThrow(DomainException::class);
    expect(LedgerEntry::query()->where('event_type', 'SECURITY_DEPOSIT_FUND')->count())->toBe(0);
})->with(['kyc', 'tenant', 'user', 'wallet']);

it('fails closed when the wallet and required security deposit assets differ', function (): void {
    app(UpdateTenantBusinessSettingsAction::class)->execute($this->tenant, [
        'required_security_deposit_amount' => '50', 'required_security_deposit_asset' => 'USD', 'allow_wallet_topup' => true, 'allow_withdrawal' => false,
    ], AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail());
    $application = app(SubmitKycApplicationAction::class)->execute(
        $this->tenant, $this->user, 'MY', 'DEPOSIT-ASSET-'.$this->user->id,
        kycTestImage('asset-front.png'), kycTestImage('asset-back.png'),
    );
    app(ApproveKycAction::class)->execute($this->tenant->id, $application->id, $this->owner);
    Wallet::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'asset_code' => 'EUR', 'status' => 'ACTIVE']);

    expect(fn () => phaseSixFund($this))->toThrow(DomainException::class, 'assets do not match');
    expect(LedgerEntry::query()->where('event_type', 'SECURITY_DEPOSIT_FUND')->count())->toBe(0);
});

it('keeps tenant boundaries and admin deposit views read only', function (): void {
    $wallet = phaseSixWallet($this);
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    expect(fn () => app(FundSecurityDepositAction::class)->execute($tenantB->id, $this->user->id, (string) Str::uuid(), '50'))->toThrow(ModelNotFoundException::class);
    $this->actingAs($this->owner, 'tenant_admin')->get("http://a.localhost/admin/users/{$this->user->id}/wallet")
        ->assertOk()->assertInertia(fn ($page) => $page->where('wallet.securityDepositRequired.amount', '50.00000000')->where('wallet.securityDepositSatisfied', false));
    expect(collect(Route::getRoutes())->pluck('uri')->filter(fn (string $uri): bool => preg_match('/security-deposit.*(adjust|refund|release)/', $uri) === 1)->values()->all())->toBe(['security-deposit/refund'])
        ->and($wallet->fresh()->tenant_id)->toBe($this->tenant->id);
    $this->post('http://a.localhost/admin/security-deposit/refund')->assertNotFound();
});

it('renders one business activity row and server-backed dashboard action', function (): void {
    phaseSixWallet($this);
    phaseSixFund($this);
    $activity = collect(app(UserWalletQuery::class)->get($this->tenant->id, $this->user->id)['activity']);
    expect($activity->where('eventType', 'SECURITY_DEPOSIT_FUND')->count())->toBe(1)
        ->and($activity->firstWhere('eventType', 'SECURITY_DEPOSIT_FUND')['amount'])->toBe('-50.00000000');
    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/wallet')->assertOk()->assertInertia(fn ($page) => $page
        ->where('eligibility.depositSatisfied', true));
    $this->get('http://a.localhost/dashboard')->assertOk()->assertInertia(fn ($page) => $page
        ->where('wallet.available.amount', '50.00000000')
        ->where('wallet.withdrawalAvailable', false)
        ->where('activity', fn ($items): bool => collect($items)->contains(fn ($item): bool => $item['eventType'] === 'SECURITY_DEPOSIT_FUND' && $item['amount'] === '-50.00000000')));
});
