<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    Storage::fake('private');
    Queue::fake();
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
});

function approvePhaseFourUser(Tenant $tenant, User $user): void
{
    $application = app(SubmitKycApplicationAction::class)->execute($tenant, $user, 'MY', 'WALLET-'.$user->id, kycTestImage('wallet-front.png'), kycTestImage('wallet-back.png'));
    $reviewer = AdminUser::query()->where('email', $tenant->slug === 'tenant-a' ? 'owner@a.localhost' : 'owner@b.localhost')->firstOrFail();
    app(ApproveKycAction::class)->execute($tenant->id, $application->id, $reviewer);
}

it('does not create a wallet on GET and blocks activation without approved KYC', function (): void {
    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/wallet')->assertOk()->assertInertia(fn ($page) => $page
        ->component('user/Wallet')->where('eligibility.wallet', null)->where('eligibility.canActivate', false));
    expect(Wallet::query()->count())->toBe(0);

    $this->post('http://a.localhost/wallet/activate')->assertSessionHasErrors('form');
    expect(Wallet::query()->count())->toBe(0);
});

it('activates an approved user wallet once with zero-balance accounts and no fake entry', function (): void {
    approvePhaseFourUser($this->tenant, $this->user);
    $first = app(ActivateUserWalletAction::class)->execute($this->tenant->id, $this->user->id, (string) Str::uuid());
    $second = app(ActivateUserWalletAction::class)->execute($this->tenant->id, $this->user->id, (string) Str::uuid());

    expect($first->created)->toBeTrue()->and($second->created)->toBeFalse()
        ->and($second->wallet->id)->toBe($first->wallet->id)
        ->and(Wallet::query()->count())->toBe(1)
        ->and(LedgerAccount::query()->where('wallet_id', $first->wallet->id)->count())->toBe(5)
        ->and(LedgerAccount::query()->where('tenant_id', $this->tenant->id)->whereNull('wallet_id')->count())->toBe(4)
        ->and(LedgerAccount::query()->where('tenant_id', $this->tenant->id)->pluck('balance')->unique()->all())->toBe(['0.00000000'])
        ->and(DB::table('ledger_entries')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'USER_WALLET_ACTIVATED')->count())->toBe(1);
});

it('serves real wallet balances and deposit qualification as decimal strings', function (): void {
    approvePhaseFourUser($this->tenant, $this->user);
    app(ActivateUserWalletAction::class)->execute($this->tenant->id, $this->user->id);

    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/wallet')->assertOk()->assertInertia(fn ($page) => $page
        ->where('eligibility.available.amount', '0.00000000')
        ->where('eligibility.available.asset', 'USD')
        ->where('eligibility.depositCurrent.amount', '0.00000000')
        ->where('eligibility.depositRequired.amount', '100.00000000')
        ->where('eligibility.depositRemaining.amount', '100.00000000')
        ->where('eligibility.depositSatisfied', false)
        ->where('eligibility.canUseCardService', false)
        ->where('activity', []));
});

it('blocks new activation for suspended users and tenants while existing wallets remain readable', function (string $restriction): void {
    approvePhaseFourUser($this->tenant, $this->user);
    app(ActivateUserWalletAction::class)->execute($this->tenant->id, $this->user->id);
    if ($restriction === 'user') {
        $this->user->update(['status' => UserStatus::Suspended]);
    } else {
        $this->tenant->update(['status' => TenantStatus::Suspended, 'suspended_at' => now()]);
    }

    $this->actingAs($this->user->fresh(), 'tenant_user')->get('http://a.localhost/wallet')->assertOk();
    $this->post('http://a.localhost/wallet/activate')->assertRedirect('/account/restricted');
})->with(['user', 'tenant']);

it('rejects direct activation for inactive tenant or user even without HTTP middleware', function (string $restriction): void {
    approvePhaseFourUser($this->tenant, $this->user);
    $restriction === 'user'
        ? $this->user->update(['status' => UserStatus::Suspended])
        : $this->tenant->update(['status' => TenantStatus::Suspended, 'suspended_at' => now()]);

    expect(fn () => app(ActivateUserWalletAction::class)->execute($this->tenant->id, $this->user->id))->toThrow(DomainException::class);
    expect(Wallet::query()->count())->toBe(0);
})->with(['user', 'tenant']);

it('keeps admin wallet and ledger reads tenant scoped and read only', function (): void {
    approvePhaseFourUser($this->tenant, $this->user);
    app(ActivateUserWalletAction::class)->execute($this->tenant->id, $this->user->id);
    $adminA = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $userB = User::query()->where('email', 'user@b.localhost')->firstOrFail();

    $this->actingAs($adminA, 'tenant_admin')->get("http://a.localhost/admin/users/{$this->user->id}/wallet")->assertOk()->assertInertia(fn ($page) => $page->component('tenant-admin/UserWallet')->where('wallet.available.amount', '0.00000000'));
    $this->get("http://a.localhost/admin/users/{$this->user->id}/ledger")->assertOk()->assertInertia(fn ($page) => $page->component('tenant-admin/UserLedger')->where('entries.data', []));
    $this->get("http://a.localhost/admin/users/{$userB->id}/wallet")->assertNotFound();
    $this->get("http://a.localhost/admin/users/{$userB->id}/ledger")->assertNotFound();

    expect(collect(Route::getRoutes())->pluck('uri')->filter(fn (string $uri): bool => str_contains($uri, 'balance') || str_contains($uri, 'adjust'))->all())->toBe([])
        ->and(LedgerAccount::query()->where('account_type', LedgerAccountType::UserAvailable)->value('balance'))->toBe('0.00000000');
});
