<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Payment\CreateTrc20WalletTopupAction;
use App\Application\Payment\ProcessIncomingTrc20TransferAction;
use App\Application\SecurityDeposit\AllocateInitialDepositAction;
use App\Application\SecurityDeposit\SecurityDepositFundingQuery;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Domain\SecurityDeposit\Models\InitialDepositIntent;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Withdrawal\Contracts\BlockchainGatewayInterface;
use App\Domain\Withdrawal\DTOs\IncomingBlockchainTransfer;
use App\Infrastructure\Providers\Blockchain\MockBlockchainGateway;
use App\Support\Errors\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed();
    Storage::fake('private');
    Queue::fake();
    config([
        'payment.trc20_deposit_address' => 'T111111111111111111111111111111111',
        'payment.trc20_token_contract' => 'T222222222222222222222222222222222',
        'payment.trc20_required_confirmations' => 20,
    ]);
    $this->app->instance(BlockchainGatewayInterface::class, new MockBlockchainGateway('CONFIRMED'));
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    // Fresh test fixture only, before any Wallet or Ledger account exists.
    $this->tenant->update(['default_asset' => 'USDT']);
    $this->tenant->businessSettings()->update([
        'required_security_deposit_asset' => 'USDT',
        'required_security_deposit_amount' => '100',
        'allow_wallet_topup' => true,
    ]);
    $this->user = User::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $application = app(SubmitKycApplicationAction::class)->execute(
        $this->tenant->fresh(), $this->user, 'MY', 'DEPOSIT-ENTRY-'.$this->user->id,
        kycTestImage('entry-front.png'), kycTestImage('entry-back.png'),
    );
    app(ApproveKycAction::class)->execute($this->tenant->id, $application->id,
        AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail());
});

function activationTopup($test, string $amount, ?string $requestId = null)
{
    return app(CreateTrc20WalletTopupAction::class)->execute(
        $test->tenant->id, $test->user->id, $amount, $requestId ?? (string) Str::uuid(), forDeposit: true,
    );
}

it('returns home when a deposit success receipt is no longer available', function (): void {
    $this->actingAs($this->user, 'tenant_user')
        ->get('http://a.localhost/security-deposit/success')
        ->assertRedirect('http://a.localhost/dashboard');
    expect(Wallet::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('opens the deposit page before a wallet exists without creating financial state', function (): void {
    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/security-deposit')
        ->assertOk()->assertInertia(fn ($page) => $page->component('user/SecurityDeposit')
        ->where('preview.minimumTopup.amount', '100.00000000')
        ->where('preview.available.amount', '0.00000000')
        ->where('preview.topupAvailable', true)->where('preview.canFund', false));
    expect(Wallet::query()->count())->toBe(0)
        ->and(LedgerAccount::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('rejects below-minimum amounts without provisioning a wallet or allocating an order', function (string $amount): void {
    expect(fn () => activationTopup($this, $amount))->toThrow(DomainException::class);
    expect(Wallet::query()->count())->toBe(0)
        ->and(WalletTopupOrder::query()->count())->toBe(0)
        ->and(LedgerAccount::query()->count())->toBe(0);
})->with(['99.99', '0', '100.001']);

it('uses the current company requirement and rounds only the input minimum upward', function (): void {
    $this->tenant->businessSettings()->update(['required_security_deposit_amount' => '123.45678901']);
    $preview = app(SecurityDepositFundingQuery::class)->preview($this->tenant->id, $this->user->id);
    expect($preview['remaining']['amount'])->toBe('123.45678901')
        ->and($preview['minimumTopup']['amount'])->toBe('123.46000000');
    expect(fn () => activationTopup($this, '123.45'))->toThrow(DomainException::class);
    expect(activationTopup($this, '123.46')->order->requested_amount)->toBe('123.46000000');
});

it('atomically provisions a wallet and replays payment instructions even after the minimum changes', function (): void {
    $id = (string) Str::uuid();
    $first = activationTopup($this, '150', $id);
    $this->tenant->businessSettings()->update(['required_security_deposit_amount' => '200']);
    $again = activationTopup($this, '150.00', $id);
    expect($again->order->id)->toBe($first->order->id)
        ->and(Wallet::query()->count())->toBe(1)
        ->and(WalletTopupOrder::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and(LedgerAccount::query()->where('balance', '<>', '0')->count())->toBe(0);
    expect(fn () => activationTopup($this, '201', $id))->toThrow(DomainException::class);
});

it('credits the full enlarged payment and allocates only the required deposit once', function (): void {
    $order = activationTopup($this, '150')->order;
    $transfer = new IncomingBlockchainTransfer('TRON', hash('sha256', $order->id), 0,
        $order->token_contract, $order->deposit_address, $order->expected_amount, 20, CarbonImmutable::now());
    app(ProcessIncomingTrc20TransferAction::class)->execute($transfer);
    $intent = InitialDepositIntent::query()->where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->firstOrFail();
    app(AllocateInitialDepositAction::class)->execute($this->tenant->id, $intent->id);
    app(ProcessIncomingTrc20TransferAction::class)->execute($transfer);
    app(AllocateInitialDepositAction::class)->execute($this->tenant->id, $intent->id);
    expect($order->expected_amount)->toBe('150.01000000')
        ->and(LedgerAccount::query()->where('wallet_id', $order->wallet_id)->where('account_type', 'USER_AVAILABLE')->value('balance'))->toBe('50.01000000')
        ->and(LedgerAccount::query()->where('wallet_id', $order->wallet_id)->where('account_type', 'USER_SECURITY_DEPOSIT')->value('balance'))->toBe('100.00000000')
        ->and(LedgerEntry::query()->where('event_type', 'SECURITY_DEPOSIT_FUND')->count())->toBe(1);
});

it('fails closed for unavailable company settings without changing currency or provisioning', function (string $gate): void {
    match ($gate) {
        'asset' => $this->tenant->update(['default_asset' => 'USD']),
        'disabled' => $this->tenant->businessSettings()->update(['allow_wallet_topup' => false]),
        'address' => config(['payment.trc20_deposit_address' => '']),
        'user' => $this->user->update(['status' => 'SUSPENDED']),
        'tenant' => $this->tenant->update(['status' => 'SUSPENDED']),
    };
    expect(fn () => activationTopup($this, '150'))->toThrow(DomainException::class);
    expect(Wallet::query()->count())->toBe(0)->and(WalletTopupOrder::query()->count())->toBe(0);
})->with(['asset', 'disabled', 'address', 'user', 'tenant']);

it('uses the resolved company and signed-in user and returns directly to deposit payment instructions', function (): void {
    $this->actingAs($this->user, 'tenant_user')->post('http://a.localhost/security-deposit/top-ups', [
        'request_id' => (string) Str::uuid(), 'requested_amount' => '120',
        'tenant_id' => (string) Str::uuid(), 'user_id' => (string) Str::uuid(), 'asset' => 'USD', 'amount' => '1',
    ])->assertRedirect();
    $order = WalletTopupOrder::query()->sole();
    expect($order->tenant_id)->toBe($this->tenant->id)->and($order->user_id)->toBe($this->user->id)
        ->and($order->asset_code)->toBe('USDT')->and($order->requested_amount)->toBe('120.00000000');
    $this->get("http://a.localhost/wallet/top-ups/{$order->id}/return?deposit=1")
        ->assertOk()->assertInertia(fn ($page) => $page->where('depositFlow', true));
    $this->get("http://b.localhost/wallet/top-ups/{$order->id}/return?deposit=1")->assertRedirect();
});

it('enables the payment button after unused USD normalization and credits the entire USDT amount', function (): void {
    legacyUsdAccountingFixtures();
    $this->tenant->refresh();
    $wallet = app(ActivateUserWalletAction::class)->execute($this->tenant->id, $this->user->id)->wallet;
    expect(app(SecurityDepositFundingQuery::class)->preview($this->tenant->id, $this->user->id)['topupAvailable'])->toBeFalse();
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
    (require database_path('migrations/2026_09_13_000400_normalize_unused_usd_wallets_to_usdt.php'))->up();
    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/security-deposit')
        ->assertOk()->assertInertia(fn ($page) => $page->where('preview.topupAvailable', true)
        ->where('preview.minimumTopup.asset', 'USDT')->where('preview.minimumTopup.amount', '100.00000000'));
    $order = activationTopup($this, '100')->order;
    expect($order->wallet_id)->toBe($wallet->id)->and($order->expected_amount)->toBe('100.01000000');
    $transfer = new IncomingBlockchainTransfer('TRON', hash('sha256', $order->id), 0,
        $order->token_contract, $order->deposit_address, $order->expected_amount, 20, CarbonImmutable::now());
    app(ProcessIncomingTrc20TransferAction::class)->execute($transfer);
    $intent = InitialDepositIntent::query()->where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->firstOrFail();
    app(AllocateInitialDepositAction::class)->execute($this->tenant->id, $intent->id);
    app(ProcessIncomingTrc20TransferAction::class)->execute($transfer);
    expect(LedgerAccount::query()->where('tenant_id', $this->tenant->id)->where('wallet_id', $wallet->id)->where('account_type', 'USER_AVAILABLE')->value('balance'))->toBe('0.01000000')
        ->and(LedgerAccount::query()->where('tenant_id', $this->tenant->id)->where('wallet_id', $wallet->id)->where('account_type', 'USER_SECURITY_DEPOSIT')->value('balance'))->toBe('100.00000000')
        ->and(LedgerEntry::query()->where('event_type', 'WALLET_TOPUP_CREDIT')->count())->toBe(1);
});
