<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Payment\CreateTrc20WalletTopupAction;
use App\Application\Payment\ExpireTrc20TopupsAction;
use App\Application\Payment\ProcessIncomingTrc20TransferAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Payment\Enums\WalletTopupStatus;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Withdrawal\Contracts\BlockchainGatewayInterface;
use App\Domain\Withdrawal\DTOs\IncomingBlockchainTransfer;
use App\Infrastructure\Providers\Blockchain\MockBlockchainGateway;
use App\Infrastructure\Providers\Blockchain\UnavailableBlockchainGateway;
use App\Support\Errors\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    config([
        'payment.trc20_deposit_address' => 'T111111111111111111111111111111111',
        'payment.trc20_token_contract' => 'T222222222222222222222222222222222',
        'payment.trc20_validity_minutes' => 30,
        'payment.trc20_required_confirmations' => 20,
        'payment.trc20_mock_incoming_transfers' => [],
    ]);
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    [$this->user, $this->wallet] = prepareTrc20User($this->tenant, $this->user, 'A');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    $this->app->forgetInstance(BlockchainGatewayInterface::class);
});

/** @return array{User,Wallet} */
function prepareTrc20User(Tenant $tenant, User $user, string $identity): array
{
    $tenant->update(['default_asset' => 'USDT']);
    $tenant->businessSettings()->update([
        'required_security_deposit_asset' => 'USDT',
        'allow_wallet_topup' => true,
    ]);
    $application = app(SubmitKycApplicationAction::class)->execute(
        $tenant->fresh(), $user, 'MY', "TRC20-{$identity}-{$user->id}",
        kycTestImage("trc20-{$identity}-front.png"), kycTestImage("trc20-{$identity}-back.png"),
    );
    $reviewer = AdminUser::query()->where('email', 'owner@'.($tenant->slug === 'tenant-a' ? 'a' : 'b').'.localhost')->firstOrFail();
    app(ApproveKycAction::class)->execute($tenant->id, $application->id, $reviewer);

    return [$user, app(ActivateUserWalletAction::class)->execute($tenant->id, $user->id)->wallet];
}

function createTrc20Topup($test, string $amount = '100', ?string $requestId = null): WalletTopupOrder
{
    return app(CreateTrc20WalletTopupAction::class)->execute(
        $test->tenant->id, $test->user->id, $amount, $requestId ?? (string) Str::uuid(),
    )->order;
}

function trc20Transfer(WalletTopupOrder $order, int $confirmations = 20, ?string $txHash = null, array $overrides = []): IncomingBlockchainTransfer
{
    return new IncomingBlockchainTransfer(
        $overrides['network'] ?? 'TRON',
        $txHash ?? hash('sha256', 'trc20-'.$order->id),
        $overrides['transfer_index'] ?? 0,
        $overrides['token_contract'] ?? $order->token_contract,
        $overrides['destination'] ?? $order->deposit_address,
        $overrides['amount'] ?? $order->expected_amount,
        $confirmations,
        $overrides['occurred_at'] ?? CarbonImmutable::now(),
    );
}

it('allocates a 0.01 through 0.99 identifier and stores one financial truth', function (): void {
    $order = createTrc20Topup($this, '100.50');

    expect($order->requested_amount)->toBe('100.50000000')
        ->and($order->identification_increment)->toBe('0.01000000')
        ->and($order->expected_amount)->toBe('100.51000000')
        ->and($order->amount)->toBe($order->expected_amount)
        ->and($order->asset_code)->toBe('USDT')
        ->and($order->network_code)->toBe('TRON')
        ->and($order->created_at->diffInMinutes($order->expires_at))->toBe(30.0);
});

it('keeps allocation idempotent and rejects changed requested amount', function (): void {
    $requestId = (string) Str::uuid();
    $first = createTrc20Topup($this, '100', $requestId);
    $retry = createTrc20Topup($this, '100.00', $requestId);
    $this->app->instance(BlockchainGatewayInterface::class, new UnavailableBlockchainGateway);
    $outageRetry = createTrc20Topup($this, '100', $requestId);

    expect($retry->id)->toBe($first->id)->and($outageRetry->id)->toBe($first->id)
        ->and($retry->expected_amount)->toBe($first->expected_amount)
        ->and(WalletTopupOrder::query()->where('request_id', $requestId)->count())->toBe(1);
    try {
        createTrc20Topup($this, '101', $requestId);
        $this->fail('Changed idempotent input must be rejected.');
    } catch (DomainException $exception) {
        expect($exception->errorCode)->toBe('IDEMPOTENCY_CONFLICT');
    }
});

it('rejects non-string overprecision and non-positive requested amounts', function (): void {
    expect(fn () => createTrc20Topup($this, '1.001'))->toThrow(DomainException::class)
        ->and(fn () => createTrc20Topup($this, '0'))->toThrow(DomainException::class)
        ->and(fn () => app(CreateTrc20WalletTopupAction::class)->execute($this->tenant->id, $this->user->id, 10.0, (string) Str::uuid()))->toThrow(DomainException::class);
});

it('never reuses an active expected amount across users or tenants', function (): void {
    $first = createTrc20Topup($this);
    $second = createTrc20Topup($this);
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $userB = User::query()->where('tenant_id', $tenantB->id)->firstOrFail();
    [, $walletB] = prepareTrc20User($tenantB, $userB, 'B');
    $third = app(CreateTrc20WalletTopupAction::class)->execute($tenantB->id, $userB->id, '100', (string) Str::uuid())->order;

    expect([$first->expected_amount, $second->expected_amount, $third->expected_amount])->each->toBeString()
        ->and(collect([$first->expected_amount, $second->expected_amount, $third->expected_amount])->unique()->count())->toBe(3)
        ->and($third->wallet_id)->toBe($walletB->id);
});

it('uses every lower-history suffix before increasing reuse and exhausts 99 active slots', function (): void {
    $orders = collect();
    foreach (range(1, 99) as $_) {
        $orders->push(createTrc20Topup($this));
    }

    expect($orders->pluck('identification_increment')->unique()->count())->toBe(99);
    try {
        createTrc20Topup($this);
        $this->fail('The hundredth active allocation must be rejected.');
    } catch (DomainException $exception) {
        expect($exception->errorCode)->toBe('TOPUP_AMOUNT_SLOTS_EXHAUSTED');
    }

    DB::table('wallet_topup_orders')->whereIn('id', $orders->pluck('id'))->update(['status' => 'CANCELLED', 'cancelled_at' => now(), 'updated_at' => now()]);
    $reuseOne = createTrc20Topup($this);
    DB::table('wallet_topup_orders')->where('id', $reuseOne->id)->update(['status' => 'CANCELLED', 'cancelled_at' => now(), 'updated_at' => now()]);
    $reuseTwo = createTrc20Topup($this);

    expect($reuseOne->identification_increment)->toBe('0.01000000')
        ->and($reuseTwo->identification_increment)->toBe('0.02000000');
});

it('prevents active expected-amount collisions between different requested bases', function (): void {
    $orders = collect();
    foreach (range(1, 51) as $_) {
        $orders->push(createTrc20Topup($this, '100'));
    }
    $colliding = $orders->firstWhere('expected_amount', '100.51000000');
    $differentBase = createTrc20Topup($this, '100.50');

    expect($colliding)->not->toBeNull()
        ->and($differentBase->expected_amount)->toBe('100.52000000');
});

it('keeps unresolved expected amounts reserved in both allocation and PostgreSQL', function (WalletTopupStatus $status): void {
    $reserved = createTrc20Topup($this);
    DB::table('wallet_topup_orders')->where('id', $reserved->id)->update([
        'status' => $status->value,
        'updated_at' => now(),
    ]);

    $next = createTrc20Topup($this);
    expect($next->expected_amount)->not->toBe($reserved->expected_amount);

    $duplicate = $reserved->getAttributes();
    $duplicate['id'] = (string) Str::uuid();
    $duplicate['request_id'] = (string) Str::uuid();
    $duplicate['request_hash'] = str_repeat('b', 64);
    $duplicate['status'] = WalletTopupStatus::Pending->value;
    $duplicate['created_at'] = now();
    $duplicate['updated_at'] = now();
    expect(fn () => DB::table('wallet_topup_orders')->insert($duplicate))->toThrow(QueryException::class);
})->with([
    'UNKNOWN' => WalletTopupStatus::Unknown,
    'REQUIRES_REVIEW' => WalletTopupStatus::RequiresReview,
]);

it('releases an unresolved amount only after it becomes definitively closed', function (): void {
    $orders = collect();
    foreach (range(1, 99) as $_) {
        $orders->push(createTrc20Topup($this));
    }
    $reserved = $orders->first();
    DB::table('wallet_topup_orders')->where('id', $reserved->id)->update([
        'status' => WalletTopupStatus::Unknown->value,
        'updated_at' => now(),
    ]);

    expect(fn () => createTrc20Topup($this))->toThrow(DomainException::class);

    DB::table('wallet_topup_orders')->where('id', $reserved->id)->update([
        'status' => WalletTopupStatus::Failed->value,
        'failed_at' => now(),
        'updated_at' => now(),
    ]);
    $reused = createTrc20Topup($this);

    expect($reused->expected_amount)->toBe($reserved->expected_amount);
});

it('releases a CREDITED suffix while preferring all still-unused eligible slots', function (): void {
    $orders = collect();
    foreach (range(1, 99) as $_) {
        $orders->push(createTrc20Topup($this));
    }
    $credited = $orders->first();
    app(ProcessIncomingTrc20TransferAction::class)->execute(trc20Transfer($credited));
    $reused = createTrc20Topup($this);

    expect($credited->fresh()->status)->toBe(WalletTopupStatus::Credited)
        ->and($reused->expected_amount)->toBe($credited->expected_amount);
});

it('keeps the existing tenant user KYC wallet and feature eligibility gates', function (string $case): void {
    match ($case) {
        'tenant' => $this->tenant->update(['status' => TenantStatus::Suspended, 'suspended_at' => now()]),
        'user' => $this->user->update(['status' => UserStatus::Suspended]),
        'wallet' => $this->wallet->update(['status' => 'SUSPENDED']),
        'setting' => $this->tenant->businessSettings()->update(['allow_wallet_topup' => false]),
    };

    expect(fn () => createTrc20Topup($this))->toThrow(DomainException::class);
})->with(['tenant', 'user', 'wallet', 'setting']);

it('expires only undetected orders and releases their active reservation', function (): void {
    CarbonImmutable::setTestNow('2026-09-10 14:00:00');
    $expired = createTrc20Topup($this);
    CarbonImmutable::setTestNow('2026-09-10 14:31:00');

    expect(app(ExpireTrc20TopupsAction::class)->execute())->toBe(1)
        ->and($expired->fresh()->status)->toBe(WalletTopupStatus::Expired);
    $newOrders = collect();
    foreach (range(1, 99) as $_) {
        $newOrders->push(createTrc20Topup($this));
    }
    expect($newOrders->pluck('expected_amount'))->toContain($expired->expected_amount);
});

it('keeps a detected pending-confirmation transfer reserved beyond nominal expiry', function (): void {
    CarbonImmutable::setTestNow('2026-09-10 14:00:00');
    $order = createTrc20Topup($this);
    CarbonImmutable::setTestNow('2026-09-10 14:28:00');
    $result = app(ProcessIncomingTrc20TransferAction::class)->execute(trc20Transfer($order, 2));
    CarbonImmutable::setTestNow('2026-09-10 14:31:00');

    expect($result)->toBe('CONFIRMING')->and(app(ExpireTrc20TopupsAction::class)->execute())->toBe(0)
        ->and($order->fresh()->status)->toBe(WalletTopupStatus::Processing)
        ->and(createTrc20Topup($this)->expected_amount)->not->toBe($order->expected_amount);

    expect(app(ProcessIncomingTrc20TransferAction::class)->execute(trc20Transfer($order, 20)))->toBe('CREDITED')
        ->and($order->fresh()->status)->toBe(WalletTopupStatus::Credited);
});

it('keeps a confirmed PAID order reserved while ledger settlement is recoverable', function (): void {
    $order = createTrc20Topup($this);
    $available = LedgerAccount::query()->where('wallet_id', $this->wallet->id)
        ->where('account_type', LedgerAccountType::UserAvailable->value)->firstOrFail();
    DB::table('ledger_accounts')->where('id', $available->id)->update(['status' => 'CLOSED', 'updated_at' => now()]);

    expect(fn () => app(ProcessIncomingTrc20TransferAction::class)->execute(trc20Transfer($order)))
        ->toThrow(DomainException::class);
    expect($order->fresh()->status)->toBe(WalletTopupStatus::Paid)
        ->and(createTrc20Topup($this)->expected_amount)->not->toBe($order->expected_amount);
});

it('does not guess a late transfer after an undetected order expires', function (): void {
    CarbonImmutable::setTestNow('2026-09-10 14:00:00');
    $order = createTrc20Topup($this);
    CarbonImmutable::setTestNow('2026-09-10 14:31:00');
    app(ExpireTrc20TopupsAction::class)->execute();

    expect(app(ProcessIncomingTrc20TransferAction::class)->execute(trc20Transfer($order, 20)))->toBe('UNMATCHED')
        ->and($order->fresh()->status)->toBe(WalletTopupStatus::Expired)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('matches an exact confirmed transfer and credits the full expected amount exactly once', function (): void {
    $order = createTrc20Topup($this, '100');
    $transfer = trc20Transfer($order);
    $action = app(ProcessIncomingTrc20TransferAction::class);

    expect($action->execute($transfer))->toBe('CREDITED')
        ->and($action->execute($transfer))->toBe('CREDITED');
    $available = LedgerAccount::query()->where('wallet_id', $this->wallet->id)->where('account_type', LedgerAccountType::UserAvailable->value)->firstOrFail();
    expect($order->fresh()->status)->toBe(WalletTopupStatus::Credited)
        ->and($order->fresh()->amount)->toBe('100.01000000')
        ->and($available->fresh()->balance)->toBe('100.01000000')
        ->and(LedgerEntry::query()->where('event_key', "wallet_topup:{$order->id}:credit")->count())->toBe(1);
});

it('does not credit pending wrong amount token destination or network observations', function (string $case): void {
    $order = createTrc20Topup($this);
    $transfer = match ($case) {
        'pending' => trc20Transfer($order, 3),
        'amount' => trc20Transfer($order, overrides: ['amount' => '100.02000000']),
        'token' => trc20Transfer($order, overrides: ['token_contract' => 'T333333333333333333333333333333333']),
        'destination' => trc20Transfer($order, overrides: ['destination' => 'T444444444444444444444444444444444']),
        'network' => trc20Transfer($order, overrides: ['network' => 'ETHEREUM']),
    };
    app(ProcessIncomingTrc20TransferAction::class)->execute($transfer);

    expect($order->fresh()->status)->not->toBe(WalletTopupStatus::Credited)
        ->and(LedgerEntry::query()->count())->toBe(0);
})->with(['pending', 'amount', 'token', 'destination', 'network']);

it('allows one normalized transfer identity to fund only one order', function (): void {
    $first = createTrc20Topup($this);
    $transfer = trc20Transfer($first);
    app(ProcessIncomingTrc20TransferAction::class)->execute($transfer);
    $second = createTrc20Topup($this);
    app(ProcessIncomingTrc20TransferAction::class)->execute(trc20Transfer($second, txHash: $transfer->txHash));

    expect($first->fresh()->status)->toBe(WalletTopupStatus::Credited)
        ->and($second->fresh()->status)->toBe(WalletTopupStatus::Pending)
        ->and(LedgerEntry::query()->count())->toBe(1);
});

it('scans mock transfers before expiring unmatched orders', function (): void {
    $order = createTrc20Topup($this);
    config(['payment.trc20_mock_incoming_transfers' => [[
        'tx_hash' => hash('sha256', 'scanner-transfer'), 'transfer_index' => 2,
        'token_contract' => $order->token_contract, 'destination' => $order->deposit_address,
        'amount' => $order->expected_amount, 'confirmations' => 20,
    ]]]);

    $this->artisan('topups:scan-trc20')->assertSuccessful();
    expect($order->fresh()->status)->toBe(WalletTopupStatus::Credited);
});

it('normalizes configurable inbound observations through the existing mock blockchain gateway', function (): void {
    $hash = hash('sha256', 'mock-inbound-observation');
    config(['payment.trc20_mock_incoming_transfers' => [[
        'network' => 'tron', 'tx_hash' => strtoupper($hash), 'transfer_index' => 3,
        'token_contract' => config('payment.trc20_token_contract'),
        'destination' => config('payment.trc20_deposit_address'), 'amount' => '50.37',
        'confirmations' => 4, 'occurred_at' => '2026-09-10T14:00:00+00:00',
    ]]]);

    $transfers = (new MockBlockchainGateway('PENDING'))->listIncomingUsdtTrc20Transfers((string) config('payment.trc20_deposit_address'));
    expect($transfers)->toHaveCount(1)
        ->and($transfers[0]->network)->toBe('TRON')
        ->and($transfers[0]->txHash)->toBe($hash)
        ->and($transfers[0]->transferIndex)->toBe(3)
        ->and($transfers[0]->amount)->toBe('50.37')
        ->and($transfers[0]->confirmations)->toBe(4);
});

it('ignores client authority fields and tenant scopes user and admin reads', function (): void {
    $this->actingAs($this->user, 'tenant_user')->post('http://a.localhost/wallet/top-ups', [
        'request_id' => (string) Str::uuid(), 'requested_amount' => '25',
        'tenant_id' => Tenant::query()->where('slug', 'tenant-b')->value('id'), 'asset' => 'BTC',
        'network' => 'ETHEREUM', 'expected_amount' => '999', 'deposit_address' => 'evil',
    ])->assertRedirect();
    $order = WalletTopupOrder::query()->sole();
    expect($order->tenant_id)->toBe($this->tenant->id)->and($order->asset_code)->toBe('USDT')
        ->and($order->network_code)->toBe('TRON')->and($order->expected_amount)->toBe('25.01000000');

    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $userB = User::query()->where('tenant_id', $tenantB->id)->firstOrFail();
    $this->actingAs($userB, 'tenant_user')->get("http://b.localhost/wallet/top-ups/{$order->id}/return")->assertNotFound();
    $adminB = AdminUser::query()->where('email', 'owner@b.localhost')->firstOrFail();
    $this->actingAs($adminB, 'tenant_admin')->get("http://b.localhost/admin/topups/{$order->id}")->assertNotFound();
});

it('enforces immutable TRC20 identity and active amount uniqueness in PostgreSQL', function (): void {
    $order = createTrc20Topup($this);
    expect(fn () => DB::table('wallet_topup_orders')->where('id', $order->id)->update(['expected_amount' => '9.99000000']))
        ->toThrow(QueryException::class);
    $duplicate = $order->getAttributes();
    $duplicate['id'] = (string) Str::uuid();
    $duplicate['request_id'] = (string) Str::uuid();
    $duplicate['request_hash'] = str_repeat('a', 64);
    unset($duplicate['created_at'], $duplicate['updated_at']);
    $duplicate['created_at'] = now();
    $duplicate['updated_at'] = now();
    expect(fn () => DB::table('wallet_topup_orders')->insert($duplicate))->toThrow(QueryException::class);
});

it('fails closed in production and exposes mock mutation only outside production', function (): void {
    $this->app->forgetInstance(BlockchainGatewayInterface::class);
    $this->app->detectEnvironment(fn (): string => 'production');
    try {
        expect(app(BlockchainGatewayInterface::class))->toBeInstanceOf(UnavailableBlockchainGateway::class)
            ->and(app(BlockchainGatewayInterface::class)->available())->toBeFalse();
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
        $this->app->forgetInstance(BlockchainGatewayInterface::class);
    }
    expect(collect(Route::getRoutes())->filter(fn ($route) => str_contains($route->uri(), '__mock/topups'))->count())->toBe(1)
        ->and(collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'admin/topups'))->flatMap(fn ($route) => $route->methods())->unique()->sort()->values()->all())->toBe(['GET', 'HEAD']);
});
