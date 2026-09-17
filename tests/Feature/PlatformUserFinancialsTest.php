<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Promotion\CommissionAccounts;
use App\Application\SecurityDeposit\FundSecurityDepositAction;
use App\Application\Tenant\UpdateTenantBusinessSettingsAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Application\Withdrawal\ApproveWithdrawalAction;
use App\Application\Withdrawal\CancelWithdrawalAction;
use App\Application\Withdrawal\CreateWithdrawalAction;
use App\Application\Withdrawal\CreateWithdrawalDestinationAction;
use App\Application\Withdrawal\RejectWithdrawalAction;
use App\Application\Withdrawal\VerifyWithdrawalTransactionAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Withdrawal\Contracts\BlockchainGatewayInterface;
use App\Domain\Withdrawal\DTOs\BlockchainTransferVerification;
use App\Domain\Withdrawal\Enums\BlockchainVerificationOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Storage::fake('private');
    Queue::fake();
    Http::preventStrayRequests();
    $this->platformOwner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->actingAs($this->platformOwner, 'platform_admin');
});

it('shows zeros without creating accounts and omits financial fields without their read permissions', function (): void {
    $count = LedgerAccount::query()->count();
    $url = 'http://admin.localhost/platform/users';
    $this->get($url)->assertOk()->assertInertia(fn ($p) => $p
        ->where('users.data.0.availableBalance', '0.00000000')->where('users.data.0.securityDeposit', '0.00000000')
        ->where('users.data.0.commission', '0.00000000')->where('users.data.0.totalWithdrawn', '0.00000000'));
    $permissions = DB::table('permissions')->whereIn('name', ['wallet.read', 'ledger.read', 'withdrawals.read'])->pluck('id');
    DB::table('role_permissions')->whereIn('permission_id', $permissions)->delete();
    $this->get($url.'?balances=1&commission=1&withdrawals=1')->assertOk()->assertInertia(fn ($p) => $p
        ->where('financialAccess', ['balances' => false, 'commission' => false, 'withdrawals' => false])
        ->missing('users.data.0.availableBalance')->missing('users.data.0.securityDeposit')
        ->missing('users.data.0.commission')->missing('users.data.0.totalWithdrawn'));
    expect(LedgerAccount::query()->count())->toBe($count);
    Http::assertNothingSent();
});

it('reads exact owned balances and successful gross withdrawal totals without multiplying or moving money', function (): void {
    $fixtures = [];
    foreach (['tenant-a' => '500.12345678', 'tenant-b' => '700.87654321'] as $slug => $credit) {
        $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();
        $user = User::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $owner = AdminUser::query()->where('email', $slug === 'tenant-a' ? 'owner@a.localhost' : 'owner@b.localhost')->firstOrFail();
        app(UpdateTenantBusinessSettingsAction::class)->execute($tenant, [
            'required_security_deposit_amount' => '100', 'required_security_deposit_asset' => 'USDT',
            'allow_wallet_topup' => true, 'allow_withdrawal' => true, 'withdrawal_fixed_fee' => '1.00',
        ], AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail());
        $kyc = app(SubmitKycApplicationAction::class)->execute($tenant, $user, 'MY', 'FINANCIALS-'.$user->id, kycTestImage(), kycTestImage('back.png'));
        app(ApproveKycAction::class)->execute($tenant->id, $kyc->id, $owner);
        $wallet = app(ActivateUserWalletAction::class)->execute($tenant->id, $user->id)->wallet;
        $available = LedgerAccount::query()->where('tenant_id', $tenant->id)->where('wallet_id', $wallet->id)->where('account_type', 'USER_AVAILABLE')->sole();
        $clearing = LedgerAccount::query()->where('tenant_id', $tenant->id)->where('account_type', 'TENANT_TOPUP_CLEARING')->sole();
        app(LedgerWriter::class)->post(new LedgerPostingPlan($tenant->id, 'USDT', 'financial-test:'.Str::uuid(), 'TEST_TOPUP', null, null, null, [
            new LedgerPostingInstruction($clearing->id, Money::of('-'.$credit, 'USDT')),
            new LedgerPostingInstruction($available->id, Money::of($credit, 'USDT')),
        ]));
        app(FundSecurityDepositAction::class)->execute($tenant->id, $user->id, (string) Str::uuid(), '100.00000000');
        DB::transaction(function () use ($tenant, $user): void {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $accounts = app(CommissionAccounts::class);
            $company = $accounts->company($tenant->id);
            $commission = $accounts->forUser($tenant->id, $user->id);
            app(LedgerWriter::class)->post(new LedgerPostingPlan($tenant->id, 'USDT', 'financial-test-commission:'.Str::uuid(), 'TEST_COMMISSION', null, null, null, [
                new LedgerPostingInstruction($company->id, Money::of('-2.12345678', 'USDT')),
                new LedgerPostingInstruction($commission->id, Money::of('2.12345678', 'USDT')),
            ]));
        });
        $fixtures[$slug] = [$tenant, $user, $owner];
    }
    [$tenant, $user, $owner] = $fixtures['tenant-a'];
    $destination = app(CreateWithdrawalDestinationAction::class)->execute($tenant->id, $user->id, 'T'.str_repeat('A', 33), 'Test');
    $create = fn (string $amount) => app(CreateWithdrawalAction::class)->execute($tenant->id, $user->id, (string) Str::uuid(), $destination->id, $amount, null, '1.00');
    $gateway = Mockery::mock(BlockchainGatewayInterface::class);
    $gateway->shouldReceive('available')->andReturn(true);
    $gateway->shouldReceive('verifyUsdtTrc20Transfer')->twice()->andReturn(new BlockchainTransferVerification(BlockchainVerificationOutcome::Confirmed, (int) config('withdrawal.minimum_confirmations')));
    app()->instance(BlockchainGatewayInterface::class, $gateway);
    foreach (['100.01', '20.02'] as $index => $amount) {
        $this->travelTo(CarbonImmutable::parse('2026-09-13T15:59:50Z'));
        $order = $create($amount);
        app(ApproveWithdrawalAction::class)->execute($tenant->id, $order->id, $owner);
        $this->travelTo(CarbonImmutable::parse($index === 0 ? '2026-09-13T15:59:59Z' : '2026-09-13T16:00:00Z'));
        app(VerifyWithdrawalTransactionAction::class)->execute($tenant->id, $order->id, $owner, str_repeat($index === 0 ? 'a' : 'b', 64));
    }
    $create('10');
    app(CancelWithdrawalAction::class)->execute($tenant->id, $user->id, $create('20')->id);
    app(RejectWithdrawalAction::class)->execute($tenant->id, $create('30')->id, $owner, 'Test rejection');
    $balances = LedgerAccount::query()->orderBy('id')->pluck('balance', 'id')->all();
    $entries = DB::table('ledger_entries')->count();
    $this->get('http://admin.localhost/platform/users?company='.$tenant->id.'&search='.$user->account_id)->assertOk()->assertInertia(fn ($p) => $p
        ->has('users.data', 1)->where('users.data.0.availableBalance', '272.21691356')
        ->where('users.data.0.securityDeposit', '100.00000000')->where('users.data.0.commission', '0.00000000')
        ->where('users.data.0.totalWithdrawn', '120.03000000')->missing('users.data.0.accounts'));
    [$otherTenant, $otherUser] = $fixtures['tenant-b'];
    $this->get('http://admin.localhost/platform/users?company='.$otherTenant->id)->assertOk()->assertInertia(fn ($p) => $p
        ->has('users.data', 1)->where('users.data.0.id', $otherUser->id)->where('users.data.0.availableBalance', '602.99999999')
        ->where('users.data.0.totalWithdrawn', '0.00000000'));
    expect(LedgerAccount::query()->orderBy('id')->pluck('balance', 'id')->all())->toBe($balances)
        ->and(DB::table('ledger_entries')->count())->toBe($entries);
    $this->get('http://admin.localhost/platform/tenants?search=tenant-a')->assertOk()->assertInertia(fn ($p) => $p
        ->has('tenants.data', 1)->where('tenants.data.0.outflow', '120.03000000')
        ->where('totals.outflow', '120.03000000')->where('totals.inflow', '0.00000000'));
    $this->get('http://admin.localhost/platform/tenants?search=tenant-b')->assertOk()->assertInertia(fn ($p) => $p
        ->where('tenants.data.0.outflow', '0.00000000')->where('totals.outflow', '0.00000000'));
    $this->get('http://admin.localhost/platform/tenants')->assertOk()->assertInertia(fn ($p) => $p
        ->has('tenants.data', 2)->where('totals.outflow', '120.03000000'));
    $this->get('http://admin.localhost/platform/demo?start=2026-09-13&end=2026-09-15')->assertOk()->assertInertia(fn ($p) => $p
        ->has('days', 3)->where('days.0.outflow', '100.01000000')->where('days.0.net', '-100.01000000')
        ->where('days.1.outflow', '20.02000000')->where('days.1.net', '-20.02000000')
        ->where('days.2.outflow', '0.00000000')->where('totals.net', '-120.03000000'));
    $this->get('http://admin.localhost/platform/demo?start=2026-09-13&end=2026-09-15&'.http_build_query(['scope' => 'selected', 'companies' => [$otherTenant->id]]))
        ->assertOk()->assertInertia(fn ($p) => $p->where('totals.outflow', '0.00000000')->where('totals.net', '0.00000000'));
    expect(LedgerAccount::query()->orderBy('id')->pluck('balance', 'id')->all())->toBe($balances)
        ->and(DB::table('ledger_entries')->count())->toBe($entries);
    Http::assertNothingSent();
});
