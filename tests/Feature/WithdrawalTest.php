<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Tenant\UpdateTenantBusinessSettingsAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Application\Withdrawal\ApproveWithdrawalAction;
use App\Application\Withdrawal\CancelWithdrawalAction;
use App\Application\Withdrawal\CreateWithdrawalAction;
use App\Application\Withdrawal\CreateWithdrawalDestinationAction;
use App\Application\Withdrawal\RejectWithdrawalAction;
use App\Application\Withdrawal\VerifyWithdrawalTransactionAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Withdrawal\Contracts\BlockchainGatewayInterface;
use App\Domain\Withdrawal\Enums\WithdrawalStatus;
use App\Domain\Withdrawal\Models\WithdrawalOrder;
use App\Domain\Withdrawal\Models\WithdrawalTransactionAttempt;
use App\Infrastructure\Providers\Blockchain\UnavailableBlockchainGateway;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    Storage::fake('private');
    Queue::fake();
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->owner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $this->address = 'T'.str_repeat('A', 33);
});

function phaseSevenSetup($test, string $available = '250.00000000'): void
{
    $test->tenant->update(['default_asset' => 'USDT']);
    app(UpdateTenantBusinessSettingsAction::class)->execute($test->tenant, [
        'required_security_deposit_amount' => '0', 'required_security_deposit_asset' => 'USDT', 'allow_wallet_topup' => true, 'allow_withdrawal' => true,
    ], $test->owner);
    $application = app(SubmitKycApplicationAction::class)->execute(
        $test->tenant, $test->user, 'MY', 'WITHDRAWAL-'.$test->user->id,
        kycTestImage('withdraw-front.png'), kycTestImage('withdraw-back.png'),
    );
    app(ApproveKycAction::class)->execute($test->tenant->id, $application->id, $test->owner);
    $test->wallet = app(ActivateUserWalletAction::class)->execute($test->tenant->id, $test->user->id)->wallet;
    $test->destination = app(CreateWithdrawalDestinationAction::class)->execute($test->tenant->id, $test->user->id, $test->address, 'Primary');
    if ($available !== '0.00000000') {
        $availableAccount = phaseSevenAccount($test->wallet, LedgerAccountType::UserAvailable);
        $clearing = LedgerAccount::query()->where('tenant_id', $test->tenant->id)->whereNull('wallet_id')->where('account_type', LedgerAccountType::TenantTopupClearing->value)->firstOrFail();
        app(LedgerWriter::class)->post(new LedgerPostingPlan(
            $test->tenant->id, 'USDT', 'withdrawal-test-credit:'.Str::uuid(), 'TEST_WALLET_CREDIT', null, null, null,
            [new LedgerPostingInstruction($clearing->id, Money::of('-'.$available, 'USDT')), new LedgerPostingInstruction($availableAccount->id, Money::of($available, 'USDT'))],
        ));
    }
}

function phaseSevenAccount(Wallet $wallet, LedgerAccountType $type): LedgerAccount
{
    return LedgerAccount::query()->where('wallet_id', $wallet->id)->where('account_type', $type->value)->firstOrFail();
}

function phaseSevenOrder($test, string $amount = '100.00000000', ?string $requestId = null): WithdrawalOrder
{
    return app(CreateWithdrawalAction::class)->execute($test->tenant->id, $test->user->id, $requestId ?? (string) Str::uuid(), $test->destination->id, $amount);
}

it('encrypts and masks immutable TRC20 destinations', function (): void {
    phaseSevenSetup($this, '0.00000000');
    $destination = $this->destination->fresh();
    expect($destination->address_ciphertext)->not->toContain($this->address)
        ->and($destination->address_hash)->not->toBe(hash('sha256', $this->address))
        ->and($destination->masked_address)->toBe('TAAAAA…AAAAA')
        ->and($destination->toArray())->not->toHaveKeys(['address_ciphertext', 'address_hash'])
        ->and(fn () => app(CreateWithdrawalDestinationAction::class)->execute($this->tenant->id, $this->user->id, 'invalid', null))->toThrow(DomainException::class)
        ->and(fn () => $destination->newQuery()->whereKey($destination->id)->update(['address_ciphertext' => 'tampered']))->toThrow(QueryException::class);
});

it('enforces tenant withdrawal policy and rejects client-selected authority fields', function (): void {
    phaseSevenSetup($this);
    $this->tenant->businessSettings()->update(['allow_withdrawal' => false]);
    expect(fn () => phaseSevenOrder($this))->toThrow(DomainException::class);

    $this->tenant->businessSettings()->update(['allow_withdrawal' => true]);
    $this->actingAs($this->user, 'tenant_user')->post('http://a.localhost/wallet/withdrawals', [
        'request_id' => (string) Str::uuid(),
        'destination_id' => $this->destination->id,
        'amount' => '10',
        'tenant_id' => Tenant::query()->where('slug', 'tenant-b')->value('id'),
        'asset' => 'USD',
        'network' => 'ETHEREUM',
    ])->assertSessionHasErrors(['tenant_id', 'asset', 'network']);
    expect(WithdrawalOrder::query()->count())->toBe(0);
});

it('creates an exact atomic withdrawal hold', function (): void {
    phaseSevenSetup($this);
    $order = phaseSevenOrder($this);
    expect($order->status)->toBe(WithdrawalStatus::Pending)
        ->and(phaseSevenAccount($this->wallet, LedgerAccountType::UserAvailable)->fresh()->balance)->toBe('150.00000000')
        ->and(phaseSevenAccount($this->wallet, LedgerAccountType::UserWithdrawalHold)->fresh()->balance)->toBe('100.00000000')
        ->and(LedgerEntry::query()->where('event_type', 'WITHDRAWAL_HOLD')->count())->toBe(1)
        ->and($order->hold_ledger_entry_id)->not->toBeNull();
});

it('enforces withdrawal lifecycle consistency and immutable attempt identity in PostgreSQL', function (): void {
    phaseSevenSetup($this);
    $order = phaseSevenOrder($this);
    expect(fn () => DB::transaction(function () use ($order): void {
        DB::table('withdrawal_orders')->where('id', $order->id)->update(['status' => 'SUCCEEDED', 'updated_at' => now()]);
        DB::statement('SET CONSTRAINTS withdrawal_order_lifecycle_consistent IMMEDIATE');
    }))->toThrow(QueryException::class);

    app(ApproveWithdrawalAction::class)->execute($this->tenant->id, $order->id, $this->owner);
    config(['withdrawal.mock_verification_mode' => 'PENDING']);
    $this->app->forgetInstance(BlockchainGatewayInterface::class);
    $tx = str_repeat('9', 64);
    app(VerifyWithdrawalTransactionAction::class)->execute($this->tenant->id, $order->id, $this->owner, $tx);
    expect(fn () => DB::table('withdrawal_transaction_attempts')->where('tx_hash', $tx)->update(['tx_hash' => str_repeat('8', 64)]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('withdrawal_transaction_attempts')->where('tx_hash', $tx)->delete())->toThrow(QueryException::class);
});

it('rejects insufficient funds and prevents two withdrawals from overspending', function (): void {
    phaseSevenSetup($this, '100.00000000');
    phaseSevenOrder($this, '80');
    expect(fn () => phaseSevenOrder($this, '30'))->toThrow(DomainException::class)
        ->and(phaseSevenAccount($this->wallet, LedgerAccountType::UserAvailable)->fresh()->balance)->toBe('20.00000000')
        ->and(WithdrawalOrder::query()->count())->toBe(1);
});

it('is idempotent and conflicts when the same request changes', function (): void {
    phaseSevenSetup($this);
    $requestId = (string) Str::uuid();
    $first = phaseSevenOrder($this, '100', $requestId);
    $again = phaseSevenOrder($this, '100.00000000', $requestId);
    expect($again->id)->toBe($first->id)->and(LedgerEntry::query()->where('event_type', 'WITHDRAWAL_HOLD')->count())->toBe(1)
        ->and(fn () => phaseSevenOrder($this, '101', $requestId))->toThrow(DomainException::class);
});

it('user cancellation and admin rejection release the exact hold once', function (string $actor): void {
    phaseSevenSetup($this);
    $order = phaseSevenOrder($this);
    if ($actor === 'user') {
        app(CancelWithdrawalAction::class)->execute($this->tenant->id, $this->user->id, $order->id);
        app(CancelWithdrawalAction::class)->execute($this->tenant->id, $this->user->id, $order->id);
        expect($order->fresh()->status)->toBe(WithdrawalStatus::Cancelled);
    } else {
        app(RejectWithdrawalAction::class)->execute($this->tenant->id, $order->id, $this->owner, 'Risk review declined');
        app(RejectWithdrawalAction::class)->execute($this->tenant->id, $order->id, $this->owner, 'Risk review declined');
        expect($order->fresh()->status)->toBe(WithdrawalStatus::Rejected);
    }
    expect(phaseSevenAccount($this->wallet, LedgerAccountType::UserAvailable)->fresh()->balance)->toBe('250.00000000')
        ->and(phaseSevenAccount($this->wallet, LedgerAccountType::UserWithdrawalHold)->fresh()->balance)->toBe('0.00000000')
        ->and(LedgerEntry::query()->where('event_type', 'WITHDRAWAL_RELEASE')->count())->toBe(1);
})->with(['user', 'admin']);

it('approval keeps the full hold and blocks user cancellation', function (): void {
    phaseSevenSetup($this);
    $order = phaseSevenOrder($this);
    app(ApproveWithdrawalAction::class)->execute($this->tenant->id, $order->id, $this->owner);
    expect($order->fresh()->status)->toBe(WithdrawalStatus::Approved)
        ->and(phaseSevenAccount($this->wallet, LedgerAccountType::UserWithdrawalHold)->fresh()->balance)->toBe('100.00000000')
        ->and(fn () => app(CancelWithdrawalAction::class)->execute($this->tenant->id, $this->user->id, $order->id))->toThrow(DomainException::class);
});

it('does not settle invalid or pending blockchain outcomes', function (string $mode, string $expectedStatus): void {
    phaseSevenSetup($this);
    $order = phaseSevenOrder($this);
    app(ApproveWithdrawalAction::class)->execute($this->tenant->id, $order->id, $this->owner);
    config(['withdrawal.mock_verification_mode' => $mode]);
    $this->app->forgetInstance(BlockchainGatewayInterface::class);
    app(VerifyWithdrawalTransactionAction::class)->execute($this->tenant->id, $order->id, $this->owner, str_repeat('a', 64));
    expect($order->fresh()->status->value)->toBe($expectedStatus)
        ->and(phaseSevenAccount($this->wallet, LedgerAccountType::UserWithdrawalHold)->fresh()->balance)->toBe('100.00000000')
        ->and(LedgerEntry::query()->where('event_type', 'WITHDRAWAL_SETTLE')->count())->toBe(0);
})->with([
    ['FAILED', 'APPROVED'], ['WRONG_DESTINATION', 'APPROVED'], ['WRONG_AMOUNT', 'APPROVED'], ['WRONG_TOKEN', 'APPROVED'], ['PENDING', 'VERIFYING'],
]);

it('settles an exact confirmed transfer once and preserves the tx hash', function (): void {
    phaseSevenSetup($this);
    $order = phaseSevenOrder($this);
    app(ApproveWithdrawalAction::class)->execute($this->tenant->id, $order->id, $this->owner);
    $tx = str_repeat('b', 64);
    app(VerifyWithdrawalTransactionAction::class)->execute($this->tenant->id, $order->id, $this->owner, $tx);
    app(VerifyWithdrawalTransactionAction::class)->execute($this->tenant->id, $order->id, $this->owner, $tx);
    expect($order->fresh()->status)->toBe(WithdrawalStatus::Succeeded)
        ->and($order->fresh()->submitted_tx_hash)->toBe($tx)
        ->and(phaseSevenAccount($this->wallet, LedgerAccountType::UserWithdrawalHold)->fresh()->balance)->toBe('0.00000000')
        ->and(LedgerAccount::query()->where('tenant_id', $this->tenant->id)->whereNull('wallet_id')->where('account_type', LedgerAccountType::TenantWithdrawalClearing->value)->value('balance'))->toBe('100.00000000')
        ->and(LedgerEntry::query()->where('event_type', 'WITHDRAWAL_SETTLE')->count())->toBe(1);
});

it('never allows one transaction hash to verify two orders', function (): void {
    phaseSevenSetup($this);
    $first = phaseSevenOrder($this, '50');
    $second = phaseSevenOrder($this, '50');
    app(ApproveWithdrawalAction::class)->execute($this->tenant->id, $first->id, $this->owner);
    app(ApproveWithdrawalAction::class)->execute($this->tenant->id, $second->id, $this->owner);
    $tx = str_repeat('c', 64);
    app(VerifyWithdrawalTransactionAction::class)->execute($this->tenant->id, $first->id, $this->owner, $tx);
    expect(fn () => app(VerifyWithdrawalTransactionAction::class)->execute($this->tenant->id, $second->id, $this->owner, $tx))->toThrow(DomainException::class)
        ->and(WithdrawalTransactionAttempt::query()->where('tx_hash', $tx)->count())->toBe(1);
});

it('keeps user and admin withdrawal reads inside the resolved tenant', function (): void {
    phaseSevenSetup($this);
    $order = phaseSevenOrder($this, '25');
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $userB = User::query()->where('tenant_id', $tenantB->id)->firstOrFail();
    $adminB = AdminUser::query()->where('email', 'owner@b.localhost')->firstOrFail();

    $this->actingAs($userB, 'tenant_user')->get("http://b.localhost/wallet/withdrawals/{$order->id}")->assertNotFound();
    $this->actingAs($adminB, 'tenant_admin')->get("http://b.localhost/admin/withdrawals/{$order->id}")->assertNotFound();
});

it('requires review permission and recent authentication to reveal the full address', function (): void {
    phaseSevenSetup($this);
    $order = phaseSevenOrder($this);
    $this->actingAs($this->owner, 'tenant_admin')->post("http://a.localhost/admin/withdrawals/{$order->id}/reveal")->assertForbidden();
    $this->post('http://a.localhost/admin/withdrawals/recent-auth', ['password' => 'local-password'])->assertRedirect();
    $this->post("http://a.localhost/admin/withdrawals/{$order->id}/reveal")->assertRedirect();
    $this->get("http://a.localhost/admin/withdrawals/{$order->id}")->assertOk()->assertInertia(fn ($page) => $page->where('revealedAddress', $this->address));
    expect(json_encode(AuditLog::query()->where('action', 'WITHDRAWAL_ADDRESS_REVEALED')->value('after_data')))->not->toContain($this->address);
});

it('keeps mock verification unavailable in production and exposes no manual success route', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    $this->app->forgetInstance(BlockchainGatewayInterface::class);
    try {
        expect(app(BlockchainGatewayInterface::class))->toBeInstanceOf(UnavailableBlockchainGateway::class)
            ->and(app(BlockchainGatewayInterface::class)->available())->toBeFalse();
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
        $this->app->forgetInstance(BlockchainGatewayInterface::class);
    }
    expect(collect(Route::getRoutes())->pluck('uri')->filter(fn (string $uri): bool => preg_match('/withdraw.*(success|balance|hold-amount|manual)/i', $uri) === 1)->all())->toBe([]);
});
