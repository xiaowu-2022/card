<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Promotion\CompanyFundBookQuery;
use App\Application\Tenant\UpdateTenantBusinessSettingsAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Application\Withdrawal\ApproveWithdrawalAction;
use App\Application\Withdrawal\CancelWithdrawalAction;
use App\Application\Withdrawal\CreateWithdrawalAction;
use App\Application\Withdrawal\CreateWithdrawalDestinationAction;
use App\Application\Withdrawal\RejectWithdrawalAction;
use App\Application\Withdrawal\UserWithdrawalQuery;
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
use App\Domain\Withdrawal\DTOs\BlockchainTransferVerification;
use App\Domain\Withdrawal\Enums\BlockchainVerificationOutcome;
use App\Domain\Withdrawal\Enums\WithdrawalStatus;
use App\Domain\Withdrawal\Models\WithdrawalOrder;
use App\Domain\Withdrawal\Models\WithdrawalTransactionAttempt;
use App\Infrastructure\Providers\Blockchain\UnavailableBlockchainGateway;
use App\Support\Errors\DomainException;
use App\Support\Logging\SensitiveDataRedactor;
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

function phaseSevenPercentageFee($test, string $fee): void
{
    app(UpdateTenantBusinessSettingsAction::class)->execute($test->tenant, [
        'required_security_deposit_amount' => '0', 'required_security_deposit_asset' => 'USDT',
        'allow_wallet_topup' => true, 'allow_withdrawal' => true, 'withdrawal_fee_percent' => $fee,
    ], AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail());
}

it('configures a company percentage fee without moving money or changing other companies', function (): void {
    phaseSevenSetup($this);
    $entries = LedgerEntry::query()->count();
    $this->actingAs($this->owner, 'tenant_admin')->post('http://a.localhost/admin/settings/business', [
        'allow_wallet_topup' => true, 'allow_withdrawal' => true, 'withdrawal_fee_percent' => '1.25',
    ])->assertForbidden();
    $platform = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->actingAs($platform, 'platform_admin')->post("http://admin.localhost/platform/tenants/{$this->tenant->id}/configuration/settings/business", [
        'allow_wallet_topup' => true, 'allow_withdrawal' => true, 'withdrawal_fee_percent' => '1.25',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($this->owner, 'tenant_admin')->get('http://a.localhost/admin/settings/business')->assertOk()->assertInertia(fn ($page) => $page->where('settings.business.withdrawalFeePercent', '1.25000000'));
    expect($this->tenant->businessSettings()->value('withdrawal_fee_percent'))->toBe('1.25000000')
        ->and(Tenant::query()->where('slug', 'tenant-b')->firstOrFail()->businessSettings->withdrawal_fee_percent)->toBe('0.00000000')
        ->and(LedgerEntry::query()->count())->toBe($entries);
    app(UpdateTenantBusinessSettingsAction::class)->execute($this->tenant, [
        'required_security_deposit_amount' => '0', 'required_security_deposit_asset' => 'USDT',
        'allow_wallet_topup' => true, 'allow_withdrawal' => true,
    ], AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail());
    expect($this->tenant->businessSettings()->value('withdrawal_fee_percent'))->toBe('1.25000000');
    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/wallet/withdraw')
        ->assertOk()->assertInertia(fn ($page) => $page->where('feePercent', '1.25000000'));
});

it('rejects negative excessive-precision or non-decimal percentage fees', function (mixed $fee): void {
    $data = ['allow_wallet_topup' => true, 'allow_withdrawal' => true, 'withdrawal_fee_percent' => $fee];
    $platform = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->actingAs($platform, 'platform_admin')->post("http://admin.localhost/platform/tenants/{$this->tenant->id}/configuration/settings/business", $data)
        ->assertSessionHasErrors('withdrawal_fee_percent');
    expect(fn () => app(UpdateTenantBusinessSettingsAction::class)->execute($this->tenant, $data, AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail()))->toThrow(DomainException::class)
        ->and($this->tenant->businessSettings()->value('withdrawal_fee_percent'))->toBe('0.00000000');
})->with(['-1', '100', '1e2', '1000000000000', '', null, 1.25]);

it('requires the current fee quote and rolls back direct address creation for stale or impossible net amounts', function (?string $quote, string $amount): void {
    phaseSevenSetup($this);
    phaseSevenPercentageFee($this, '2.50');
    $addresses = DB::table('withdrawal_destinations')->count();
    $audits = AuditLog::query()->count();
    expect(fn () => app(CreateWithdrawalAction::class)->executeWithAddress(
        $this->tenant->id, $this->user->id, (string) Str::uuid(), 'T'.str_repeat('B', 33), $amount, null, $quote,
    ))->toThrow(DomainException::class)
        ->and(WithdrawalOrder::query()->count())->toBe(0)
        ->and(DB::table('withdrawal_destinations')->count())->toBe($addresses)
        ->and(AuditLog::query()->count())->toBe($audits)
        ->and(phaseSevenAccount($this->wallet, LedgerAccountType::UserAvailable)->fresh()->balance)->toBe('250.00000000');
})->with([[null, '100'], ['0', '100'], ['1', '100'], ['2.50', '2.50'], ['2.50', '2.49']]);

it('snapshots the confirmed percentage fee and preserves retry economics after settings change', function (): void {
    phaseSevenSetup($this);
    $legacy = phaseSevenOrder($this, '10');
    phaseSevenPercentageFee($this, '2.50');
    $request = (string) Str::uuid();
    $data = ['request_id' => $request, 'address' => $this->address, 'amount' => '100.01', 'confirmed' => true, 'expected_fee' => '2.50025'];
    $this->actingAs($this->user, 'tenant_user')->post('http://a.localhost/wallet/withdrawals', $data)->assertRedirect()->assertSessionHasNoErrors();
    $order = WithdrawalOrder::query()->where('tenant_id', $this->tenant->id)->where('request_id', $request)->firstOrFail();
    expect($order->fee_amount)->toBe('2.50025000')->and($order->receive_amount)->toBe('97.50975000')
        ->and(phaseSevenAccount($this->wallet, LedgerAccountType::UserWithdrawalHold)->balance)->toBe('110.01000000');
    phaseSevenPercentageFee($this, '3');
    $this->post('http://a.localhost/wallet/withdrawals', $data)->assertRedirect()->assertSessionHasNoErrors();
    expect($order->fresh()->fee_amount)->toBe('2.50025000')
        ->and(LedgerEntry::query()->where('event_type', 'WITHDRAWAL_HOLD')->count())->toBe(2)
        ->and(phaseSevenOrder($this, '10', $legacy->request_id)->id)->toBe($legacy->id)
        ->and($legacy->fresh()->receive_amount)->toBe('10.00000000')
        ->and(fn () => app(CreateWithdrawalAction::class)->execute($this->tenant->id, $this->user->id, $request, $this->destination->id, '100.01', null, '3'))->toThrow(DomainException::class);
    $this->get("http://a.localhost/wallet/withdrawals/{$order->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->where('order.feeAmount', '2.50025000')->where('order.receiveAmount', '97.50975000'));
});

it('verifies the exact net payout and settles gross plus fee exactly once', function (): void {
    phaseSevenSetup($this);
    phaseSevenPercentageFee($this, '2.50');
    $order = app(CreateWithdrawalAction::class)->execute($this->tenant->id, $this->user->id, (string) Str::uuid(), $this->destination->id, '100.01', null, '2.50025');
    app(ApproveWithdrawalAction::class)->execute($this->tenant->id, $order->id, $this->owner);
    phaseSevenPercentageFee($this, '9');
    $tx = str_repeat('e', 64);
    $gateway = Mockery::mock(BlockchainGatewayInterface::class);
    $gateway->shouldReceive('available')->twice()->andReturn(true);
    $gateway->shouldReceive('verifyUsdtTrc20Transfer')->once()->with($tx, $this->address, '97.50975000')
        ->andReturn(new BlockchainTransferVerification(BlockchainVerificationOutcome::Confirmed, (int) config('withdrawal.minimum_confirmations')));
    $this->app->instance(BlockchainGatewayInterface::class, $gateway);
    app(VerifyWithdrawalTransactionAction::class)->execute($this->tenant->id, $order->id, $this->owner, $tx);
    app(VerifyWithdrawalTransactionAction::class)->execute($this->tenant->id, $order->id, $this->owner, $tx);
    $settled = $order->fresh();
    expect($settled->status)->toBe(WithdrawalStatus::Succeeded)
        ->and(phaseSevenAccount($this->wallet, LedgerAccountType::UserAvailable)->balance)->toBe('149.99000000')
        ->and(phaseSevenAccount($this->wallet, LedgerAccountType::UserWithdrawalHold)->balance)->toBe('0.00000000');
    $postings = DB::table('ledger_postings as p')->join('ledger_accounts as a', 'a.id', '=', 'p.ledger_account_id')
        ->where('p.tenant_id', $this->tenant->id)->where('p.ledger_entry_id', $settled->settlement_ledger_entry_id)->pluck('p.delta', 'a.account_type')->map(fn ($delta) => Money::of($delta, 'USDT')->amount())->all();
    expect($postings)->toEqual(['USER_WITHDRAWAL_HOLD' => '-100.01000000', 'TENANT_WITHDRAWAL_CLEARING' => '97.50975000', 'TENANT_FEE_REVENUE' => '2.50025000']);
    $book = app(CompanyFundBookQuery::class)->execute($this->tenant->id, null, 1);
    expect($book['lifetimeTotals']['withdrawals'])->toBe('97.50975000')->and($book['lifetimeTotals']['feeIncome'])->toBe('2.50025000')
        ->and(collect($book['rows'])->pluck('id')->unique()->count())->toBe(count($book['rows']))
        ->and(collect($book['rows'])->where('type', 'WITHDRAWAL_FEE_INCOME')->count())->toBe(1);
});

it('returns the entire gross hold without fee income on cancellation or rejection', function (string $mode): void {
    phaseSevenSetup($this);
    phaseSevenPercentageFee($this, '2.50');
    $order = app(CreateWithdrawalAction::class)->execute($this->tenant->id, $this->user->id, (string) Str::uuid(), $this->destination->id, '100', null, '2.50');
    for ($i = 0; $i < 2; $i++) {
        if ($mode === 'cancel') {
            app(CancelWithdrawalAction::class)->execute($this->tenant->id, $this->user->id, $order->id);
        } else {
            app(RejectWithdrawalAction::class)->execute($this->tenant->id, $order->id, $this->owner, 'Declined');
        }
    }
    expect(phaseSevenAccount($this->wallet, LedgerAccountType::UserAvailable)->balance)->toBe('250.00000000')
        ->and(LedgerAccount::query()->where('tenant_id', $this->tenant->id)->where('account_type', LedgerAccountType::TenantFeeRevenue->value)->value('balance'))->toBe('0.00000000')
        ->and(LedgerEntry::query()->where('event_type', 'WITHDRAWAL_RELEASE')->count())->toBe(1);
})->with(['cancel', 'reject']);

it('guards immutable fee snapshots and generated net in PostgreSQL and models', function (): void {
    phaseSevenSetup($this);
    $order = phaseSevenOrder($this);
    expect(fn () => $order->update(['fee_amount' => '1']))->toThrow(LogicException::class)
        ->and(fn () => DB::table('withdrawal_orders')->where('id', $order->id)->update(['fee_amount' => '1']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('withdrawal_orders')->where('id', $order->id)->update(['receive_amount' => '99']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('tenant_business_settings')->where('tenant_id', $this->tenant->id)->update(['withdrawal_fee_percent' => '-1']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('tenant_business_settings')->where('tenant_id', $this->tenant->id)->update(['withdrawal_fee_percent' => '100']))->toThrow(QueryException::class);
});

function phaseSevenSetup($test, string $available = '250.00000000'): void
{
    $test->tenant->update(['default_asset' => 'USDT']);
    app(UpdateTenantBusinessSettingsAction::class)->execute($test->tenant, [
        'required_security_deposit_amount' => '0', 'required_security_deposit_asset' => 'USDT', 'allow_wallet_topup' => true, 'allow_withdrawal' => true,
    ], AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail());
    $ocr = Mockery::mock(\App\Domain\Kyc\Contracts\KycOcrProviderInterface::class);
    $ocr->shouldReceive('name')->andReturn('TEST');
    $ocr->shouldReceive('extractIdentityDocument')->andReturn(new \App\Domain\Kyc\DTOs\KycOcrResultDTO(\App\Domain\Kyc\Enums\KycOcrOutcome::Success, 'WITHDRAWAL-'.$test->user->id));
    app()->instance(\App\Domain\Kyc\Contracts\KycOcrProviderInterface::class, $ocr);
    $application = app(SubmitKycApplicationAction::class)->execute(
        $test->tenant, $test->user, 'CN', 'WITHDRAWAL-'.$test->user->id,
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

it('submits a typed address and amount in one atomic request without a separate address step', function (): void {
    $this->withoutVite();
    phaseSevenSetup($this);
    $address = 'T'.str_repeat('B', 33);
    $request = ['request_id' => (string) Str::uuid(), 'address' => $address, 'amount' => '20.01', 'confirmed' => true];
    $this->actingAs($this->user, 'tenant_user')->post('http://a.localhost/wallet/withdrawals', $request)->assertRedirect();
    $order = WithdrawalOrder::query()->where('tenant_id', $this->tenant->id)->sole();
    expect($order->amount)->toBe('20.01000000')->and($order->status)->toBe(WithdrawalStatus::Pending)
        ->and($order->destination->address_ciphertext)->not->toContain($address)
        ->and(phaseSevenAccount($this->wallet, LedgerAccountType::UserAvailable)->balance)->toBe('229.99000000');
    $this->post('http://a.localhost/wallet/withdrawals', $request)->assertRedirect();
    expect(WithdrawalOrder::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->where('event_type', 'WITHDRAWAL_HOLD')->count())->toBe(1);
    $this->get('http://a.localhost/wallet/withdraw')->assertOk()->assertInertia(fn ($page) => $page
        ->component('user/Withdraw')->missing('history'));
    $this->get('http://a.localhost/wallet/withdrawals')->assertOk()->assertInertia(fn ($page) => $page
        ->component('user/WithdrawalHistory')->where('history.data.0.id', $order->id)
        ->where('history.data.0.amount', '20.01000000')->where('history.data.0.state', 'pending')
        ->where('history.data.0.maskedAddress', 'TBBBBB…BBBBB'));
});

it('rolls back the inline address and audit when withdrawal validation fails', function (string $amount): void {
    phaseSevenSetup($this);
    $before = DB::table('withdrawal_destinations')->count();
    $auditCount = AuditLog::query()->count();
    expect(fn () => app(CreateWithdrawalAction::class)->executeWithAddress($this->tenant->id, $this->user->id,
        (string) Str::uuid(), 'T'.str_repeat('B', 33), $amount))->toThrow(DomainException::class);
    expect(DB::table('withdrawal_destinations')->count())->toBe($before)
        ->and(AuditLog::query()->count())->toBe($auditCount)
        ->and(WithdrawalOrder::query()->count())->toBe(0)
        ->and(phaseSevenAccount($this->wallet, LedgerAccountType::UserAvailable)->balance)->toBe('250.00000000');
})->with(['0', '-1', '251', '1.123456789']);

it('binds inline withdrawal retries to the exact address and amount', function (): void {
    phaseSevenSetup($this);
    $action = app(CreateWithdrawalAction::class);
    $id = (string) Str::uuid();
    $address = 'T'.str_repeat('B', 33);
    $order = $action->executeWithAddress($this->tenant->id, $this->user->id, $id, ' '.$address.' ', '10');
    expect($action->executeWithAddress($this->tenant->id, $this->user->id, $id, $address, '10.00')->id)->toBe($order->id)
        ->and(fn () => $action->executeWithAddress($this->tenant->id, $this->user->id, $id, 'T'.str_repeat('C', 33), '10'))->toThrow(DomainException::class)
        ->and(fn () => $action->executeWithAddress($this->tenant->id, $this->user->id, $id, $address, '11'))->toThrow(DomainException::class);
    $action->executeWithAddress($this->tenant->id, $this->user->id, (string) Str::uuid(), $address, '5');
    expect(DB::table('withdrawal_destinations')->count())->toBe(2)
        ->and(WithdrawalOrder::query()->count())->toBe(2)
        ->and(phaseSevenAccount($this->wallet, LedgerAccountType::UserAvailable)->balance)->toBe('235.00000000');
});

it('requires inline confirmation and does not flash raw withdrawal addresses', function (): void {
    phaseSevenSetup($this);
    $address = 'T'.str_repeat('B', 33);
    $data = ['request_id' => (string) Str::uuid(), 'address' => $address, 'amount' => '10'];
    $this->actingAs($this->user, 'tenant_user')->post('http://a.localhost/wallet/withdrawals', $data)
        ->assertSessionHasErrors('confirmed')->assertSessionMissing('_old_input.address');
    $this->post('http://a.localhost/wallet/withdrawals', [...$data, 'confirmed' => true, 'destination_id' => $this->destination->id])
        ->assertSessionHasErrors('address');
    expect(WithdrawalOrder::query()->count())->toBe(0)
        ->and(app(SensitiveDataRedactor::class)->redact(['address' => $address]))->toBe(['address' => '[REDACTED]']);
});

it('paginates only the signed-in users withdrawal records without exposing protected address fields', function (): void {
    $this->withoutVite();
    phaseSevenSetup($this);
    for ($i = 0; $i < 12; $i++) {
        phaseSevenOrder($this, '1');
    }
    $query = app(UserWithdrawalQuery::class);
    $first = $query->history($this->tenant->id, $this->user->id);
    $second = $query->history($this->tenant->id, $this->user->id, 2);
    expect($first['history']['data'])->toHaveCount(10)->and($second['history']['data'])->toHaveCount(2)
        ->and($first['history']['lastPage'])->toBe(2);
    $row = $first['history']['data'][0];
    expect(array_keys($row))->toBe(['id', 'amount', 'asset', 'feeAmount', 'receiveAmount', 'maskedAddress', 'state', 'requestedAt']);
    $other = $this->user->replicate(['account_id']);
    $other->email = 'withdraw-history@example.test';
    $other->save();
    expect($query->history($this->tenant->id, $other->id)['history']['data'])->toBe([]);
    $foreign = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $foreignUser = User::query()->where('tenant_id', $foreign->id)->firstOrFail();
    expect($query->history($foreign->id, $foreignUser->id)['history']['data'])->toBe([]);
    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/wallet/withdrawals?page=2')
        ->assertOk()->assertInertia(fn ($page) => $page->component('user/WithdrawalHistory')->has('history.data', 2)->where('history.currentPage', 2)->missing('available'));
    $this->get('http://a.localhost/wallet/withdrawals?page=0')->assertSessionHasErrors('page');
    $this->actingAs($other, 'tenant_user')->get('http://a.localhost/wallet/withdrawals')
        ->assertOk()->assertInertia(fn ($page) => $page->has('history.data', 0));
    $this->actingAs($other, 'tenant_user')->get('http://a.localhost/wallet/withdrawals/'.$row['id'])->assertNotFound();
});

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
    $actions = ['WITHDRAWAL_VERIFICATION_REQUESTED', $mode === 'PENDING' ? 'WITHDRAWAL_VERIFICATION_PENDING' : 'WITHDRAWAL_VERIFICATION_REJECTED'];
    foreach ($actions as $action) {
        $audit = AuditLog::query()->where('tenant_id', $this->tenant->id)->where('resource_id', $order->id)->where('action', $action)->sole();
        expect($audit->actor_id)->toBe($this->owner->id)->and($audit->created_at)->not->toBeNull();
    }
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

it('retains the operator and submission time when withdrawal verification throws', function (): void {
    phaseSevenSetup($this);
    $order = phaseSevenOrder($this);
    app(ApproveWithdrawalAction::class)->execute($this->tenant->id, $order->id, $this->owner);
    $gateway = Mockery::mock(BlockchainGatewayInterface::class);
    $gateway->shouldReceive('available')->andReturnTrue();
    $gateway->shouldReceive('verifyUsdtTrc20Transfer')->once()->andThrow(new RuntimeException('Verification timeout'));
    $this->app->instance(BlockchainGatewayInterface::class, $gateway);
    expect(fn () => app(VerifyWithdrawalTransactionAction::class)->execute($this->tenant->id, $order->id, $this->owner, str_repeat('e', 64)))
        ->toThrow(RuntimeException::class, 'Verification timeout');
    expect($order->fresh()->status)->toBe(WithdrawalStatus::Verifying)
        ->and(LedgerEntry::query()->where('event_type', 'WITHDRAWAL_SETTLE')->count())->toBe(0);
    foreach (['WITHDRAWAL_VERIFICATION_REQUESTED', 'WITHDRAWAL_VERIFICATION_UNAVAILABLE'] as $action) {
        $audit = AuditLog::query()->where('resource_id', $order->id)->where('action', $action)->sole();
        expect($audit->actor_id)->toBe($this->owner->id)->and($audit->created_at)->not->toBeNull();
    }
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

it('rounds TRON percentage fees up to a transfer unit and requires explicit configuration', function (): void {
    phaseSevenSetup($this);
    $this->tenant->businessSettings()->update(['withdrawal_fee_percent' => null]);
    expect(fn () => phaseSevenOrder($this))->toThrow(DomainException::class, 'Complete the required configuration first.');
    phaseSevenPercentageFee($this, '0.00000001');
    $order = app(CreateWithdrawalAction::class)->execute($this->tenant->id, $this->user->id, (string) Str::uuid(), $this->destination->id, '0.01', null, '0.000001');
    expect($order->fee_amount)->toBe('0.00000100')->and($order->receive_amount)->toBe('0.00999900');
});
