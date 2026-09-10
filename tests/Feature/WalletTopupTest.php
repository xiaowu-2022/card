<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Payment\CreateWalletTopupAction;
use App\Application\Payment\CreditWalletTopupAction;
use App\Application\Payment\PersistPaymentProviderEventAction;
use App\Application\Payment\ProcessPaymentProviderEventAction;
use App\Application\Payment\QueryPaymentStatusAction;
use App\Application\Payment\TenantPaymentReturnUrl;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Payment\Contracts\PaymentProviderInterface;
use App\Domain\Payment\DTOs\NormalizedPaymentEvent;
use App\Domain\Payment\DTOs\PaymentInitiationRequest;
use App\Domain\Payment\DTOs\PaymentProviderResult;
use App\Domain\Payment\DTOs\VerifiedPaymentWebhook;
use App\Domain\Payment\Enums\PaymentEventProcessingStatus;
use App\Domain\Payment\Enums\PaymentProviderTransactionStatus;
use App\Domain\Payment\Enums\WalletTopupStatus;
use App\Domain\Payment\Exceptions\PaymentProviderTimeoutException;
use App\Domain\Payment\Models\PaymentProviderEvent;
use App\Domain\Payment\Models\PaymentProviderTransaction;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Infrastructure\Providers\Payment\UnavailablePaymentProvider;
use App\Jobs\CreditWalletTopupJob;
use App\Jobs\InitiateWalletTopupPaymentJob;
use App\Jobs\ProcessPaymentProviderEventJob;
use App\Jobs\QueryPaymentStatusJob;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config(['payment.mock_mode' => 'PENDING']);
    $this->seed();
    Storage::fake('private');
    Queue::fake();
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $application = app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'MY', 'TOPUP-'.$this->user->id, kycTestImage('topup-front.png'), kycTestImage('topup-back.png'));
    $reviewer = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    app(ApproveKycAction::class)->execute($this->tenant->id, $application->id, $reviewer);
    $this->wallet = app(ActivateUserWalletAction::class)->execute($this->tenant->id, $this->user->id)->wallet;
});

function createPhaseFiveTopup($test, string $amount = '100.00000000', ?string $requestId = null): WalletTopupOrder
{
    return app(CreateWalletTopupAction::class)->execute(
        $test->tenant->id, $test->user->id, $test->wallet->id, $amount, 'USD', $requestId ?? (string) Str::uuid(),
        'http://a.localhost/wallet/top-ups/__ORDER__/return',
    )->order;
}

function phaseFiveEvent($test, WalletTopupOrder $order, string $eventId, string $status = 'SUCCEEDED', string $amount = '100.00000000', string $asset = 'USD', string $eventType = 'PAYMENT_SUCCEEDED'): PaymentProviderEvent
{
    $transaction = PaymentProviderTransaction::query()->where('wallet_topup_order_id', $order->id)->firstOrFail();
    $body = json_encode([
        'event_id' => $eventId, 'event_type' => $eventType, 'provider_transaction_id' => $transaction->provider_transaction_id,
        'provider_request_id' => $transaction->provider_request_id, 'status' => $status, 'amount' => $amount, 'asset' => $asset,
        'tenant_id' => 'untrusted-client-value',
    ], JSON_THROW_ON_ERROR);
    $request = Request::create('/webhooks/payments/mock', 'POST', [], [], [], [], $body);
    $request->headers->set('X-Mock-Signature', hash_hmac('sha256', $body, (string) config('payment.mock_webhook_secret')));

    return app(PersistPaymentProviderEventAction::class)->execute('mock', $request);
}

it('creates one tenant-scoped order and provider transaction for an idempotent request', function (): void {
    $requestId = (string) Str::uuid();
    $first = createPhaseFiveTopup($this, '100', $requestId);
    $second = createPhaseFiveTopup($this, '100.00000000', $requestId);

    expect($second->id)->toBe($first->id)
        ->and(WalletTopupOrder::query()->count())->toBe(1)
        ->and(PaymentProviderTransaction::query()->count())->toBe(1)
        ->and($first->amount)->toBe('100.00000000')
        ->and($first->status)->toBe(WalletTopupStatus::Processing);
});

it('normalizes provider initiation failure timeout unknown and trusted query success', function (string $mode): void {
    config(['payment.mock_mode' => $mode]);
    $this->app->forgetInstance(PaymentProviderInterface::class);
    $result = app(CreateWalletTopupAction::class)->execute(
        $this->tenant->id, $this->user->id, $this->wallet->id, '25.00000000', 'USD', (string) Str::uuid(),
        'http://a.localhost/wallet/top-ups/__ORDER__/return',
    );
    $transaction = PaymentProviderTransaction::query()->where('wallet_topup_order_id', $result->order->id)->firstOrFail();
    if ($mode === 'FAILED') {
        expect($result->order->fresh()->status)->toBe(WalletTopupStatus::Failed)
            ->and($transaction->status)->toBe(PaymentProviderTransactionStatus::Failed);

        return;
    }
    if (in_array($mode, ['TIMEOUT', 'UNKNOWN'], true)) {
        expect($result->order->fresh()->status)->toBe(WalletTopupStatus::Processing)
            ->and($transaction->fresh()->status)->toBe(PaymentProviderTransactionStatus::Unknown);

        return;
    }
    Queue::assertPushed(QueryPaymentStatusJob::class);
    app(QueryPaymentStatusAction::class)->execute($this->tenant->id, $transaction->id);
    expect($result->order->fresh()->status)->toBe(WalletTopupStatus::Paid)
        ->and(LedgerEntry::query()->count())->toBe(0);
})->with(['SUCCEEDED', 'FAILED', 'TIMEOUT', 'UNKNOWN']);

it('never resolves the mock payment provider in production', function (): void {
    $this->app->forgetInstance(PaymentProviderInterface::class);
    $this->app->detectEnvironment(fn (): string => 'production');
    try {
        expect(app(PaymentProviderInterface::class))->toBeInstanceOf(UnavailablePaymentProvider::class)
            ->and(app(PaymentProviderInterface::class)->available())->toBeFalse();
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
        $this->app->forgetInstance(PaymentProviderInterface::class);
    }
});

it('recovers an unknown provider transaction through a trusted successful query', function (): void {
    config(['payment.mock_mode' => 'UNKNOWN']);
    $this->app->forgetInstance(PaymentProviderInterface::class);
    $order = createPhaseFiveTopup($this, '40.00000000');
    $transaction = PaymentProviderTransaction::query()->where('wallet_topup_order_id', $order->id)->firstOrFail();
    expect($transaction->status)->toBe(PaymentProviderTransactionStatus::Unknown);

    config(['payment.mock_mode' => 'SUCCEEDED']);
    $this->app->forgetInstance(PaymentProviderInterface::class);
    app(QueryPaymentStatusAction::class)->execute($this->tenant->id, $transaction->id);
    expect($order->fresh()->status)->toBe(WalletTopupStatus::Paid);
    app(CreditWalletTopupAction::class)->execute($this->tenant->id, $order->id);
    expect($order->fresh()->status)->toBe(WalletTopupStatus::Credited);
});

it('rejects changed idempotent payloads floats invalid money and cross asset requests', function (): void {
    $requestId = (string) Str::uuid();
    createPhaseFiveTopup($this, '100', $requestId);
    expect(fn () => createPhaseFiveTopup($this, '101', $requestId))->toThrow(DomainException::class)
        ->and(fn () => app(CreateWalletTopupAction::class)->execute($this->tenant->id, $this->user->id, $this->wallet->id, 0.1, 'USD', (string) Str::uuid(), 'http://a.localhost/return'))->toThrow(DomainException::class)
        ->and(fn () => createPhaseFiveTopup($this, '0'))->toThrow(DomainException::class)
        ->and(fn () => app(CreateWalletTopupAction::class)->execute($this->tenant->id, $this->user->id, $this->wallet->id, '1', 'EUR', (string) Str::uuid(), 'http://a.localhost/return'))->toThrow(DomainException::class);
});

it('enforces creation eligibility without affecting settlement eligibility', function (string $case): void {
    match ($case) {
        'tenant' => $this->tenant->update(['status' => TenantStatus::Suspended, 'suspended_at' => now()]),
        'user' => $this->user->update(['status' => UserStatus::Suspended]),
        'wallet' => $this->wallet->update(['status' => 'SUSPENDED']),
        'setting' => $this->tenant->businessSettings()->update(['allow_wallet_topup' => false]),
    };
    expect(fn () => createPhaseFiveTopup($this))->toThrow(DomainException::class);
})->with(['tenant', 'user', 'wallet', 'setting']);

it('accepts a verified event as PAID without changing balance before settlement', function (): void {
    $order = createPhaseFiveTopup($this);
    $event = phaseFiveEvent($this, $order, 'event-paid');
    Queue::assertPushed(ProcessPaymentProviderEventJob::class);
    app(ProcessPaymentProviderEventAction::class)->execute($this->tenant->id, $event->id);
    Queue::assertPushed(CreditWalletTopupJob::class);
    $available = LedgerAccount::query()->where('wallet_id', $this->wallet->id)->where('account_type', LedgerAccountType::UserAvailable->value)->firstOrFail();
    expect($order->fresh()->status)->toBe(WalletTopupStatus::Paid)
        ->and($available->fresh()->balance)->toBe('0.00000000')
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('credits a paid top-up exactly once through the immutable ledger', function (): void {
    $order = createPhaseFiveTopup($this);
    $event = phaseFiveEvent($this, $order, 'event-credit');
    app(ProcessPaymentProviderEventAction::class)->execute($this->tenant->id, $event->id);
    $first = app(CreditWalletTopupAction::class)->execute($this->tenant->id, $order->id);
    $second = app(CreditWalletTopupAction::class)->execute($this->tenant->id, $order->id);
    $available = LedgerAccount::query()->where('wallet_id', $this->wallet->id)->where('account_type', LedgerAccountType::UserAvailable->value)->firstOrFail();
    $clearing = LedgerAccount::query()->where('tenant_id', $this->tenant->id)->whereNull('wallet_id')->where('account_type', LedgerAccountType::TenantTopupClearing->value)->firstOrFail();
    expect($second->id)->toBe($first->id)->and(LedgerEntry::query()->count())->toBe(1)
        ->and($order->fresh()->status)->toBe(WalletTopupStatus::Credited)
        ->and($available->fresh()->balance)->toBe('100.00000000')
        ->and($clearing->fresh()->balance)->toBe('-100.00000000');
});

it('deduplicates identical events and requires review for changed payload or mismatched money', function (): void {
    $order = createPhaseFiveTopup($this);
    $first = phaseFiveEvent($this, $order, 'event-duplicate');
    $second = phaseFiveEvent($this, $order, 'event-duplicate');
    expect($second->id)->toBe($first->id)->and(PaymentProviderEvent::query()->count())->toBe(1);
    expect(fn () => phaseFiveEvent($this, $order, 'event-duplicate', amount: '99.00000000'))->toThrow(DomainException::class);
    expect($first->fresh()->processing_status)->toBe(PaymentEventProcessingStatus::RequiresReview);

    $mismatch = phaseFiveEvent($this, $order, 'event-mismatch', amount: '101.00000000');
    app(ProcessPaymentProviderEventAction::class)->execute($this->tenant->id, $mismatch->id);
    expect($mismatch->fresh()->processing_status)->toBe(PaymentEventProcessingStatus::RequiresReview)
        ->and($order->fresh()->status)->not->toBe(WalletTopupStatus::Paid);
});

it('does not allow stale events to reverse paid or credited state', function (): void {
    $order = createPhaseFiveTopup($this);
    $success = phaseFiveEvent($this, $order, 'event-success');
    app(ProcessPaymentProviderEventAction::class)->execute($this->tenant->id, $success->id);
    $pending = phaseFiveEvent($this, $order, 'event-stale', 'PENDING', eventType: 'PAYMENT_PENDING');
    app(ProcessPaymentProviderEventAction::class)->execute($this->tenant->id, $pending->id);
    expect($order->fresh()->status)->toBe(WalletTopupStatus::Paid);
});

it('continues already-paid settlement after operational eligibility changes', function (string $case): void {
    $order = createPhaseFiveTopup($this);
    $event = phaseFiveEvent($this, $order, 'event-suspend-'.$case);
    app(ProcessPaymentProviderEventAction::class)->execute($this->tenant->id, $event->id);
    match ($case) {
        'user-suspended' => $this->user->update(['status' => UserStatus::Suspended]),
        'user-disabled' => $this->user->update(['status' => UserStatus::Disabled]),
        'tenant' => $this->tenant->update(['status' => TenantStatus::Suspended, 'suspended_at' => now()]),
        'wallet' => $this->wallet->update(['status' => 'SUSPENDED']),
        'setting' => $this->tenant->businessSettings()->update(['allow_wallet_topup' => false]),
    };
    app(CreditWalletTopupAction::class)->execute($this->tenant->id, $order->id);
    expect($order->fresh()->status)->toBe(WalletTopupStatus::Credited);
})->with(['user-suspended', 'user-disabled', 'tenant', 'wallet', 'setting']);

it('does not debit a credited top-up when a refund arrives', function (): void {
    $order = createPhaseFiveTopup($this);
    $success = phaseFiveEvent($this, $order, 'event-before-refund');
    app(ProcessPaymentProviderEventAction::class)->execute($this->tenant->id, $success->id);
    app(CreditWalletTopupAction::class)->execute($this->tenant->id, $order->id);
    $refund = phaseFiveEvent($this, $order, 'event-refund', 'FAILED', eventType: 'PAYMENT_REFUNDED');
    app(ProcessPaymentProviderEventAction::class)->execute($this->tenant->id, $refund->id);
    $available = LedgerAccount::query()->where('wallet_id', $this->wallet->id)->where('account_type', LedgerAccountType::UserAvailable->value)->firstOrFail();
    expect($refund->fresh()->processing_status)->toBe(PaymentEventProcessingStatus::RequiresReview)
        ->and($order->fresh()->status)->toBe(WalletTopupStatus::Credited)
        ->and($available->balance)->toBe('100.00000000')->and(LedgerEntry::query()->count())->toBe(1);
});

it('keeps credited immutable for every post-credit exception type', function (string $eventType): void {
    $order = createPhaseFiveTopup($this);
    $success = phaseFiveEvent($this, $order, 'event-credit-'.$eventType);
    app(ProcessPaymentProviderEventAction::class)->execute($this->tenant->id, $success->id);
    $entry = app(CreditWalletTopupAction::class)->execute($this->tenant->id, $order->id);
    $exception = phaseFiveEvent($this, $order, 'event-exception-'.$eventType, 'FAILED', eventType: $eventType);
    app(ProcessPaymentProviderEventAction::class)->execute($this->tenant->id, $exception->id);

    expect($order->fresh()->status)->toBe(WalletTopupStatus::Credited)
        ->and($order->fresh()->ledger_entry_id)->toBe($entry->id)
        ->and($exception->fresh()->processing_status)->toBe(PaymentEventProcessingStatus::RequiresReview);
})->with(['PAYMENT_REFUNDED', 'PAYMENT_CHARGEBACK', 'PAYMENT_REVERSAL', 'PAYMENT_DISPUTE']);

it('prevents a queued credit when a verified refund arrives before settlement', function (): void {
    $order = createPhaseFiveTopup($this);
    $success = phaseFiveEvent($this, $order, 'event-paid-before-refund');
    app(ProcessPaymentProviderEventAction::class)->execute($this->tenant->id, $success->id);
    $refund = phaseFiveEvent($this, $order, 'event-refund-before-credit', 'FAILED', eventType: 'PAYMENT_REFUNDED');
    app(ProcessPaymentProviderEventAction::class)->execute($this->tenant->id, $refund->id);
    expect($order->fresh()->status)->toBe(WalletTopupStatus::Refunded)
        ->and(fn () => app(CreditWalletTopupAction::class)->execute($this->tenant->id, $order->id))->toThrow(DomainException::class)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('treats browser return query parameters as read-only and isolates tenant orders', function (): void {
    $order = createPhaseFiveTopup($this);
    $this->actingAs($this->user, 'tenant_user')->get("http://a.localhost/wallet/top-ups/{$order->id}/return?status=paid&tenant_id=other")
        ->assertOk()->assertInertia(fn ($page) => $page->component('user/TopupStatus')->where('order.status', 'PROCESSING'));
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $userB = User::query()->where('tenant_id', $tenantB->id)->firstOrFail();
    $this->actingAs($userB, 'tenant_user')->get("http://b.localhost/wallet/top-ups/{$order->id}/return")->assertNotFound();
    expect($order->fresh()->status)->toBe(WalletTopupStatus::Processing)->and(LedgerEntry::query()->count())->toBe(0);
});

it('builds provider return URLs only from the resolved persisted active tenant domain', function (): void {
    $builder = app(TenantPaymentReturnUrl::class);
    expect($builder->forResolvedHost($this->tenant->id, 'a.localhost', 8000))
        ->toBe('http://a.localhost:8000/wallet/top-ups/__ORDER__/return')
        ->and(fn () => $builder->forResolvedHost($this->tenant->id, 'evil.example', 8000))
        ->toThrow(DomainException::class);
});

it('keeps tenant admin top-up lists and details read-only and tenant scoped', function (): void {
    $order = createPhaseFiveTopup($this);
    $adminA = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $adminB = AdminUser::query()->where('email', 'owner@b.localhost')->firstOrFail();
    $this->actingAs($adminA, 'tenant_admin')->get('http://a.localhost/admin/topups')->assertOk()
        ->assertInertia(fn ($page) => $page->component('tenant-admin/Topups')->has('orders.data', 1));
    $this->get("http://a.localhost/admin/topups/{$order->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->component('tenant-admin/TopupDetail')->where('order.status', 'PROCESSING'));
    $this->actingAs($adminB, 'tenant_admin')->get("http://b.localhost/admin/topups/{$order->id}")->assertNotFound();
    expect(collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'admin/topups'))->flatMap(fn ($route) => $route->methods())->unique()->sort()->values()->all())
        ->toBe(['GET', 'HEAD']);
});

it('rejects invalid webhook signatures and unknown mappings without trusting tenant id', function (): void {
    $body = json_encode(['event_id' => 'unknown', 'provider_request_id' => (string) Str::uuid(), 'status' => 'SUCCEEDED', 'amount' => '100.00000000', 'asset' => 'USD', 'tenant_id' => $this->tenant->id], JSON_THROW_ON_ERROR);
    $this->postJson('/webhooks/payments/mock', json_decode($body, true))->assertUnauthorized();
    $request = Request::create('/webhooks/payments/mock', 'POST', [], [], [], [], $body);
    $request->headers->set('X-Mock-Signature', hash_hmac('sha256', $body, (string) config('payment.mock_webhook_secret')));
    expect(fn () => app(PersistPaymentProviderEventAction::class)->execute('mock', $request))->toThrow(DomainException::class)
        ->and(PaymentProviderEvent::query()->count())->toBe(0);
});

it('recovery redispatches persisted events paid orders and stale unknown queries', function (): void {
    $order = createPhaseFiveTopup($this);
    $event = phaseFiveEvent($this, $order, 'event-recovery');
    DB::table('wallet_topup_orders')->where('id', $order->id)->update(['status' => WalletTopupStatus::Paid->value, 'paid_at' => now(), 'updated_at' => now()]);
    DB::table('payment_provider_transactions')->where('wallet_topup_order_id', $order->id)->update(['status' => PaymentProviderTransactionStatus::Unknown->value, 'updated_at' => now()->subHour()]);
    Queue::fake();
    $this->artisan('payments:recover')->assertSuccessful();
    Queue::assertPushed(ProcessPaymentProviderEventJob::class, fn ($job) => $job->eventId === $event->id);
    Queue::assertPushed(CreditWalletTopupJob::class, fn ($job) => $job->orderId === $order->id);
});

it('recovers the commit-before-initiation crash window with the same provider request id', function (): void {
    $order = createPhaseFiveTopup($this);
    DB::table('payment_provider_transactions')->where('wallet_topup_order_id', $order->id)->update([
        'provider_transaction_id' => null,
        'status' => PaymentProviderTransactionStatus::Pending->value,
        'initiation_attempted_at' => null,
        'initiation_lease_expires_at' => null,
        'updated_at' => now()->subMinute(),
    ]);
    Queue::fake();
    $this->artisan('payments:recover')->assertSuccessful();
    Queue::assertPushed(InitiateWalletTopupPaymentJob::class, fn ($job) => $job->tenantId === $this->tenant->id
        && $job->orderId === $order->id && str_contains($job->returnUrl, 'a.localhost'));
});

it('recovers when the provider accepted initiation but the response was lost', function (): void {
    $provider = new class implements PaymentProviderInterface
    {
        public ?PaymentInitiationRequest $accepted = null;

        public function name(): string
        {
            return 'mock';
        }

        public function available(): bool
        {
            return true;
        }

        public function initiatePayment(PaymentInitiationRequest $request): PaymentProviderResult
        {
            $this->accepted = $request;
            throw new PaymentProviderTimeoutException('Response lost after acceptance.');
        }

        public function queryPayment(string $providerRequestId): PaymentProviderResult
        {
            return new PaymentProviderResult(PaymentProviderTransactionStatus::Succeeded, $providerRequestId, 'accepted-resource', $this->accepted->amount, $this->accepted->assetCode);
        }

        public function verifyWebhook(Request $request, string $rawBody): VerifiedPaymentWebhook
        {
            return new VerifiedPaymentWebhook($rawBody);
        }

        public function normalizeWebhook(VerifiedPaymentWebhook $webhook): NormalizedPaymentEvent
        {
            throw new LogicException('Not used by this test.');
        }
    };
    $this->app->instance(PaymentProviderInterface::class, $provider);
    $order = createPhaseFiveTopup($this, '37.00000000');
    $transaction = PaymentProviderTransaction::query()->where('wallet_topup_order_id', $order->id)->firstOrFail();
    expect($transaction->status)->toBe(PaymentProviderTransactionStatus::Unknown)
        ->and($provider->accepted->providerRequestId)->toBe($transaction->provider_request_id);

    app(QueryPaymentStatusAction::class)->execute($this->tenant->id, $transaction->id);
    expect($order->fresh()->status)->toBe(WalletTopupStatus::Paid)
        ->and($order->fresh()->paid_at)->not->toBeNull();
});

it('keeps a paid order recoverable when ledger settlement temporarily fails', function (): void {
    $order = createPhaseFiveTopup($this);
    DB::table('wallet_topup_orders')->where('id', $order->id)->update([
        'status' => WalletTopupStatus::Paid->value, 'paid_at' => now(), 'updated_at' => now(),
    ]);
    $available = LedgerAccount::query()->where('wallet_id', $this->wallet->id)
        ->where('account_type', LedgerAccountType::UserAvailable->value)->firstOrFail();
    DB::table('ledger_accounts')->where('id', $available->id)->update(['status' => 'CLOSED', 'updated_at' => now()]);
    expect(fn () => app(CreditWalletTopupAction::class)->execute($this->tenant->id, $order->id))->toThrow(DomainException::class);
    expect($order->fresh()->status)->toBe(WalletTopupStatus::Paid)->and(LedgerEntry::query()->count())->toBe(0);

    DB::table('ledger_accounts')->where('id', $available->id)->update(['status' => 'ACTIVE', 'updated_at' => now()]);
    app(CreditWalletTopupAction::class)->execute($this->tenant->id, $order->id);
    expect($order->fresh()->status)->toBe(WalletTopupStatus::Credited)->and(LedgerEntry::query()->count())->toBe(1);
});

it('applies a tenant and user scoped top-up creation limiter', function (): void {
    $route = collect(Route::getRoutes())->first(fn ($candidate) => $candidate->getName() === 'user.topups.store');
    expect($route->gatherMiddleware())->toContain('throttle:wallet-topups');
});

it('enforces credited financial facts and one ledger link at the database boundary', function (): void {
    $order = createPhaseFiveTopup($this);
    expect(fn () => DB::transaction(fn () => DB::table('wallet_topup_orders')->where('id', $order->id)->update([
        'status' => WalletTopupStatus::Credited->value, 'paid_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class);

    DB::table('wallet_topup_orders')->where('id', $order->id)->update([
        'status' => WalletTopupStatus::Paid->value, 'paid_at' => now(), 'updated_at' => now(),
    ]);
    $entry = app(CreditWalletTopupAction::class)->execute($this->tenant->id, $order->id);
    expect(fn () => DB::transaction(fn () => DB::table('wallet_topup_orders')->where('id', $order->id)->update([
        'status' => WalletTopupStatus::Refunded->value, 'credited_at' => null, 'ledger_entry_id' => null, 'updated_at' => now(),
    ])))->toThrow(QueryException::class);

    $other = createPhaseFiveTopup($this, '25.00000000');
    expect(fn () => DB::transaction(fn () => DB::table('wallet_topup_orders')->where('id', $other->id)->update([
        'status' => WalletTopupStatus::Credited->value, 'paid_at' => now(), 'credited_at' => now(),
        'ledger_entry_id' => $entry->id, 'updated_at' => now(),
    ])))->toThrow(QueryException::class);

    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $userB = User::query()->where('tenant_id', $tenantB->id)->firstOrFail();
    $applicationB = app(SubmitKycApplicationAction::class)->execute(
        $tenantB, $userB, 'MY', 'TOPUP-B-'.$userB->id, kycTestImage('topup-b-front.png'), kycTestImage('topup-b-back.png'),
    );
    $reviewerB = AdminUser::query()->where('email', 'owner@b.localhost')->firstOrFail();
    app(ApproveKycAction::class)->execute($tenantB->id, $applicationB->id, $reviewerB);
    $walletB = app(ActivateUserWalletAction::class)->execute($tenantB->id, $userB->id)->wallet;
    $orderB = app(CreateWalletTopupAction::class)->execute(
        $tenantB->id, $userB->id, $walletB->id, '10', 'USD', (string) Str::uuid(),
        'http://b.localhost/wallet/top-ups/__ORDER__/return',
    )->order;
    expect(fn () => DB::transaction(fn () => DB::table('wallet_topup_orders')->where('id', $orderB->id)->update([
        'status' => WalletTopupStatus::Credited->value, 'paid_at' => now(), 'credited_at' => now(),
        'ledger_entry_id' => $entry->id, 'updated_at' => now(),
    ])))->toThrow(QueryException::class);
});

it('rejects payment financial identity mutation and duplicate provider mappings', function (): void {
    $first = createPhaseFiveTopup($this);
    $second = createPhaseFiveTopup($this, '25.00000000');
    $firstTransaction = PaymentProviderTransaction::query()->where('wallet_topup_order_id', $first->id)->firstOrFail();
    $secondTransaction = PaymentProviderTransaction::query()->where('wallet_topup_order_id', $second->id)->firstOrFail();
    expect(fn () => DB::transaction(fn () => DB::table('wallet_topup_orders')->where('id', $first->id)->update(['amount' => '101.00000000'])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('wallet_topup_orders')->where('id', $first->id)->update(['asset_code' => 'EUR'])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('payment_provider_transactions')->where('id', $secondTransaction->id)
            ->update(['provider_request_id' => $firstTransaction->provider_request_id])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('payment_provider_transactions')->where('id', $secondTransaction->id)
            ->update(['provider_transaction_id' => $firstTransaction->provider_transaction_id])))
        ->toThrow(QueryException::class);

    $event = phaseFiveEvent($this, $first, 'immutable-event');
    expect(fn () => DB::transaction(fn () => DB::table('payment_provider_events')->where('id', $event->id)->update(['amount' => '99.00000000'])))
        ->toThrow(QueryException::class);
});

it('rejects overprecision scientific notation oversized bodies and conflicting resource references', function (): void {
    $orderA = createPhaseFiveTopup($this);
    $orderB = createPhaseFiveTopup($this, '25.00000000');
    expect(fn () => phaseFiveEvent($this, $orderA, 'overprecision', amount: '100.000000001'))->toThrow(DomainException::class)
        ->and(fn () => phaseFiveEvent($this, $orderA, 'scientific', amount: '1e2'))->toThrow(DomainException::class);

    config(['payment.webhook_max_bytes' => 32]);
    $body = json_encode(['event_id' => str_repeat('x', 40)], JSON_THROW_ON_ERROR);
    $request = Request::create('/webhooks/payments/mock', 'POST', [], [], [], [], $body);
    $request->headers->set('X-Mock-Signature', hash_hmac('sha256', $body, (string) config('payment.mock_webhook_secret')));
    expect(fn () => app(PersistPaymentProviderEventAction::class)->execute('mock', $request))->toThrow(DomainException::class);
    config(['payment.webhook_max_bytes' => 65536]);

    $transactionB = PaymentProviderTransaction::query()->where('wallet_topup_order_id', $orderB->id)->firstOrFail();
    $transactionA = PaymentProviderTransaction::query()->where('wallet_topup_order_id', $orderA->id)->firstOrFail();
    $conflictBody = json_encode([
        'event_id' => 'resource-conflict', 'event_type' => 'PAYMENT_SUCCEEDED',
        'provider_request_id' => $transactionA->provider_request_id,
        'provider_transaction_id' => $transactionB->provider_transaction_id,
        'status' => 'SUCCEEDED', 'amount' => '100.00000000', 'asset' => 'USD',
    ], JSON_THROW_ON_ERROR);
    $conflictRequest = Request::create('/webhooks/payments/mock', 'POST', [], [], [], [], $conflictBody);
    $conflictRequest->headers->set('X-Mock-Signature', hash_hmac('sha256', $conflictBody, (string) config('payment.mock_webhook_secret')));
    $conflict = app(PersistPaymentProviderEventAction::class)->execute('mock', $conflictRequest);
    app(ProcessPaymentProviderEventAction::class)->execute($this->tenant->id, $conflict->id);
    expect($conflict->fresh()->processing_status)->toBe(PaymentEventProcessingStatus::RequiresReview)
        ->and($orderA->fresh()->status)->toBe(WalletTopupStatus::Processing);
});

it('reconciles the exact top-up accounting path and detects a balanced wrong entry', function (): void {
    $order = createPhaseFiveTopup($this);
    DB::table('wallet_topup_orders')->where('id', $order->id)->update([
        'status' => WalletTopupStatus::Paid->value, 'paid_at' => now(), 'updated_at' => now(),
    ]);
    app(CreditWalletTopupAction::class)->execute($this->tenant->id, $order->id);
    $this->artisan('ledger:reconcile')->assertSuccessful();
    $this->artisan('payments:reconcile')->assertSuccessful();

    $wrongOrder = createPhaseFiveTopup($this, '25.00000000');
    $available = LedgerAccount::query()->where('wallet_id', $this->wallet->id)
        ->where('account_type', LedgerAccountType::UserAvailable->value)->firstOrFail();
    $wrongClearing = LedgerAccount::query()->where('tenant_id', $this->tenant->id)->whereNull('wallet_id')
        ->where('account_type', LedgerAccountType::TenantWithdrawalClearing->value)->firstOrFail();
    $wrongEntry = app(LedgerWriter::class)->post(new LedgerPostingPlan(
        $this->tenant->id, 'USD', "wallet_topup:{$wrongOrder->id}:credit", 'WALLET_TOPUP_CREDIT',
        'WALLET_TOPUP_ORDER', $wrongOrder->id, null, [
            new LedgerPostingInstruction($wrongClearing->id, Money::of('-25', 'USD')),
            new LedgerPostingInstruction($available->id, Money::of('25', 'USD')),
        ],
    ));
    DB::table('wallet_topup_orders')->where('id', $wrongOrder->id)->update([
        'status' => WalletTopupStatus::Credited->value, 'paid_at' => now(), 'credited_at' => now(),
        'ledger_entry_id' => $wrongEntry->id, 'updated_at' => now(),
    ]);
    $this->artisan('ledger:reconcile')->assertSuccessful();
    $this->artisan('payments:reconcile')->assertFailed();
});
