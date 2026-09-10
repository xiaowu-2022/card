<?php

use App\Application\Card\ApplyCardIssueResultAction;
use App\Application\Card\CreateCardIssueAction;
use App\Application\Card\SubmitProviderCardholderAction;
use App\Application\Card\SyncCardIssueAction;
use App\Application\Card\SyncProviderCardholderAction;
use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\SecurityDeposit\FundSecurityDepositAction;
use App\Application\Tenant\UpdateTenantBusinessSettingsAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Card\Enums\CardIssueStatus;
use App\Domain\Card\Enums\ProviderCardholderStatus;
use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\Card\Models\UserCard;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProduct\Models\TenantCardProductConfig;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\CardProvider\DTOs\ProviderCardDTO;
use App\Domain\CardProvider\DTOs\ProviderOperationDTO;
use App\Domain\CardProvider\Enums\MockProviderMode;
use App\Domain\CardProvider\Enums\ProviderOperationStatus;
use App\Domain\Kyc\Models\KycApplication;
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
use App\Infrastructure\Providers\Card\MockCardProvider;
use App\Support\Errors\DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed();
    Storage::fake('private');
    Queue::fake();
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->owner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $this->product = CardProduct::query()->where('provider', 'PHOTONPAY')->firstOrFail();
    phaseTenProvider($this, MockProviderMode::Success, 'READY');
});

function phaseTenProvider($test, MockProviderMode $mode, string $cardholderMode = 'READY'): void
{
    app()->instance(CardProviderInterface::class, new MockCardProvider($mode, $cardholderMode));
}

/** @return array{wallet:Wallet,cardholder:ProviderCardholder} */
function phaseTenReadyUser($test, string $available = '100.00000000', ProviderCardholderStatus $holderStatus = ProviderCardholderStatus::Ready): array
{
    DB::transaction(function () use ($test): void {
        DB::table('tenants')->where('id', $test->tenant->id)->update(['default_asset' => 'USDT']);
        $test->tenant->refresh();
        app(UpdateTenantBusinessSettingsAction::class)->execute($test->tenant, [
            'required_security_deposit_amount' => '10',
            'required_security_deposit_asset' => 'USDT',
            'allow_wallet_topup' => true,
            'allow_withdrawal' => true,
        ], $test->owner);
    });
    $test->tenant->refresh();
    $application = app(SubmitKycApplicationAction::class)->execute(
        $test->tenant,
        $test->user,
        'MY',
        'PHASE-TEN-'.$test->user->id,
        kycTestImage('phase-ten-front.png'),
        kycTestImage('phase-ten-back.png'),
    );
    app(ApproveKycAction::class)->execute($test->tenant->id, $application->id, $test->owner);
    $wallet = app(ActivateUserWalletAction::class)->execute($test->tenant->id, $test->user->id)->wallet;
    $accounts = LedgerAccount::query()->where('tenant_id', $test->tenant->id)->where('wallet_id', $wallet->id)->get()
        ->keyBy(fn (LedgerAccount $account): string => $account->account_type->value);
    $clearing = LedgerAccount::query()->where('tenant_id', $test->tenant->id)->whereNull('wallet_id')
        ->where('account_type', LedgerAccountType::TenantTopupClearing->value)->firstOrFail();
    $total = Money::of($available, 'USDT')->add(Money::of('10', 'USDT'));
    app(LedgerWriter::class)->post(new LedgerPostingPlan(
        $test->tenant->id,
        'USDT',
        'phase-ten:test-credit:'.$test->user->id,
        'TEST_WALLET_CREDIT',
        null,
        null,
        null,
        [
            new LedgerPostingInstruction($clearing->id, Money::of('-'.$total->amount(), 'USDT')),
            new LedgerPostingInstruction($accounts[LedgerAccountType::UserAvailable->value]->id, $total),
        ],
    ));
    app(FundSecurityDepositAction::class)->execute($test->tenant->id, $test->user->id, (string) Str::uuid(), '10.00000000');
    $holder = new ProviderCardholder;
    $holder->forceFill([
        'tenant_id' => $test->tenant->id,
        'user_id' => $test->user->id,
        'provider' => 'PHOTONPAY',
        'provider_cardholder_id' => 'MOCK-HOLDER-'.$test->user->id,
        'status' => $holderStatus,
        'provider_status' => strtolower($holderStatus->value),
        'provider_review_status' => strtolower($holderStatus->value),
        'submitted_at' => now(),
        'synced_at' => now(),
    ])->save();

    return ['wallet' => $wallet, 'cardholder' => $holder];
}

function phaseTenIssue($test, string $amount = '20.00', ?string $requestId = null): CardIssueOrder
{
    return app(CreateCardIssueAction::class)->execute(
        $test->tenant->id,
        $test->user->id,
        $requestId ?? (string) Str::uuid(),
        $test->product->id,
        $amount,
    );
}

function phaseTenAccount($test, LedgerAccountType $type): LedgerAccount
{
    return LedgerAccount::query()->where('tenant_id', $test->tenant->id)->where('user_id', $test->user->id)
        ->where('account_type', $type->value)->firstOrFail();
}

it('requires approved KYC and validates all card setup fields before creating a Provider Cardholder', function (): void {
    expect(fn () => app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, []))
        ->toThrow(DomainException::class, 'Approved identity verification');

    $this->actingAs($this->user, 'tenant_user')->post('http://a.localhost/cards/cardholder', [
        'legal_first_name' => '', 'tenant_id' => $this->tenant->id, 'identity_number' => 'must-not-be-accepted',
    ])->assertSessionHasErrors(['legal_first_name', 'legal_last_name', 'date_of_birth', 'tenant_id', 'identity_number']);
    expect(ProviderCardholder::query()->count())->toBe(0);
});

it('reuses approved private KYC documents without publishing or duplicating identity data', function (): void {
    phaseTenReadyUser($this);
    ProviderCardholder::query()->delete();
    $data = [
        'legal_first_name' => 'Demo', 'legal_last_name' => 'User', 'date_of_birth' => '1990-01-02',
        'nationality_country_code' => 'MY', 'residential_address' => '1 Demo Street', 'residential_city' => 'Kuala Lumpur',
        'residential_state' => 'Kuala Lumpur', 'residential_country_code' => 'MY', 'residential_postal_code' => '50000',
    ];
    $holder = app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, $data);
    $application = KycApplication::query()->where('tenant_id', $this->tenant->id)->firstOrFail();

    expect($holder->status)->toBe(ProviderCardholderStatus::Ready)
        ->and($holder->provider_cardholder_id)->toStartWith('MOCK-HOLDER-')
        ->and(Storage::disk('private')->exists($application->front_object_key))->toBeTrue()
        ->and(Storage::disk('public')->exists($application->front_object_key))->toBeFalse()
        ->and(DB::getSchemaBuilder()->hasColumns('provider_cardholders', ['identity_number', 'front_object_key', 'back_object_key']))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'PHOTONPAY_CARDHOLDER_SUBMITTED')->count())->toBe(1);
});

it('does not issue for pending or rejected Cardholders and issues only when ready', function (ProviderCardholderStatus $status): void {
    phaseTenReadyUser($this, holderStatus: $status);
    if ($status === ProviderCardholderStatus::Ready) {
        expect(phaseTenIssue($this)->status)->toBe(CardIssueStatus::Succeeded);
    } else {
        expect(fn () => phaseTenIssue($this))->toThrow(DomainException::class, 'Card setup must be approved');
        expect(CardIssueOrder::query()->count())->toBe(0);
    }
})->with([ProviderCardholderStatus::Pending, ProviderCardholderStatus::Rejected, ProviderCardholderStatus::Ready]);

it('does not blindly recreate an unknown Cardholder and keeps linkage tenant scoped', function (): void {
    phaseTenProvider($this, MockProviderMode::Success, 'TIMEOUT');
    phaseTenReadyUser($this);
    ProviderCardholder::query()->delete();
    $data = [
        'legal_first_name' => 'Demo', 'legal_last_name' => 'User', 'date_of_birth' => '1990-01-02',
        'nationality_country_code' => 'MY', 'residential_address' => '1 Demo Street', 'residential_city' => 'Kuala Lumpur',
        'residential_state' => 'Kuala Lumpur', 'residential_country_code' => 'MY', 'residential_postal_code' => '50000',
    ];
    $first = app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, $data);
    expect($first->status)->toBe(ProviderCardholderStatus::Unknown)
        ->and(fn () => app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, $data))->toThrow(DomainException::class, 'safely reconciled')
        ->and(ProviderCardholder::query()->count())->toBe(1);

    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    expect(fn () => app(SyncProviderCardholderAction::class)->execute($tenantB->id, $this->user->id))->toThrow(ModelNotFoundException::class);
});

it('rejects below-minimum loads and insufficient combined Wallet balance', function (): void {
    phaseTenReadyUser($this, '24.99999999');
    expect(fn () => phaseTenIssue($this, '19.99'))->toThrow(DomainException::class, 'minimum');
    expect(fn () => phaseTenIssue($this, '20.00'))->toThrow(DomainException::class, 'not enough');
    expect(CardIssueOrder::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->where('event_type', 'CARD_ISSUE_FEE_HOLD')->count())->toBe(0);
});

it('rejects every client-supplied Card issue authority field', function (): void {
    phaseTenReadyUser($this);

    $this->actingAs($this->user, 'tenant_user')->post('http://a.localhost/cards/issues', [
        'request_id' => (string) Str::uuid(),
        'card_product_id' => $this->product->id,
        'initial_load_amount' => '20.00',
        'opening_fee' => '0.00',
        'provider' => 'MOCK',
        'cardBin' => 'CLIENT-BIN',
        'cardCurrency' => 'EUR',
        'cardholderId' => 'CLIENT-HOLDER',
        'wallet_id' => (string) Str::uuid(),
        'tenant_id' => Tenant::query()->where('slug', 'tenant-b')->value('id'),
        'user_id' => (string) Str::uuid(),
    ])->assertSessionHasErrors([
        'opening_fee', 'provider', 'cardBin', 'cardCurrency', 'cardholderId', 'wallet_id', 'tenant_id', 'user_id',
    ]);

    expect(CardIssueOrder::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->whereIn('event_type', ['CARD_ISSUE_FEE_HOLD', 'CARD_INITIAL_LOAD_HOLD'])->count())->toBe(0);
});

it('creates exact separate holds and settles them once on trusted provider success', function (): void {
    phaseTenReadyUser($this, '100');
    $order = phaseTenIssue($this, '20.00');

    expect($order->status)->toBe(CardIssueStatus::Succeeded)
        ->and($order->opening_fee)->toBe('5.00000000')
        ->and($order->initial_load_amount)->toBe('20.00000000')
        ->and($order->fee_hold_ledger_entry_id)->not->toBeNull()
        ->and($order->funding_hold_ledger_entry_id)->not->toBeNull()
        ->and($order->fee_settlement_ledger_entry_id)->not->toBeNull()
        ->and($order->funding_settlement_ledger_entry_id)->not->toBeNull()
        ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe('75.00000000')
        ->and(phaseTenAccount($this, LedgerAccountType::UserCardIssueHold)->balance)->toBe('0.00000000')
        ->and(phaseTenAccount($this, LedgerAccountType::UserCardFundingHold)->balance)->toBe('0.00000000')
        ->and(LedgerEntry::query()->where('event_type', 'CARD_ISSUE_FEE_HOLD')->count())->toBe(1)
        ->and(LedgerEntry::query()->where('event_type', 'CARD_INITIAL_LOAD_HOLD')->count())->toBe(1)
        ->and(LedgerEntry::query()->where('event_type', 'CARD_ISSUE_FEE_SETTLE')->count())->toBe(1)
        ->and(LedgerEntry::query()->where('event_type', 'CARD_INITIAL_LOAD_SETTLE')->count())->toBe(1)
        ->and(UserCard::query()->count())->toBe(1);
});

it('skips every zero-value fee event while retaining exact initial-funding accounting', function (): void {
    phaseTenReadyUser($this, '50');
    TenantCardProductConfig::query()->where('tenant_id', $this->tenant->id)
        ->where('card_product_id', $this->product->id)->update(['opening_fee' => '0.00000000']);
    $order = phaseTenIssue($this);

    expect($order->status)->toBe(CardIssueStatus::Succeeded)
        ->and($order->fee_hold_ledger_entry_id)->toBeNull()
        ->and($order->fee_settlement_ledger_entry_id)->toBeNull()
        ->and(LedgerEntry::query()->whereIn('event_type', ['CARD_ISSUE_FEE_HOLD', 'CARD_ISSUE_FEE_SETTLE'])->count())->toBe(0)
        ->and(LedgerEntry::query()->where('event_type', 'CARD_INITIAL_LOAD_HOLD')->count())->toBe(1)
        ->and(LedgerEntry::query()->where('event_type', 'CARD_INITIAL_LOAD_SETTLE')->count())->toBe(1)
        ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe('30.00000000');
});

it('releases both holds on definitive failure and keeps both holds on timeout', function (MockProviderMode $mode, CardIssueStatus $status, string $available, string $feeHold, string $fundingHold): void {
    phaseTenProvider($this, $mode);
    phaseTenReadyUser($this, '100');
    $order = phaseTenIssue($this);

    expect($order->status)->toBe($status)
        ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe($available)
        ->and(phaseTenAccount($this, LedgerAccountType::UserCardIssueHold)->balance)->toBe($feeHold)
        ->and(phaseTenAccount($this, LedgerAccountType::UserCardFundingHold)->balance)->toBe($fundingHold)
        ->and(UserCard::query()->count())->toBe(0);
})->with([
    'definitive failure' => [MockProviderMode::Failed, CardIssueStatus::Failed, '100.00000000', '0.00000000', '0.00000000'],
    'timeout' => [MockProviderMode::Timeout, CardIssueStatus::Unknown, '75.00000000', '5.00000000', '20.00000000'],
]);

it('keeps both holds when a claimed success has a mismatched provider balance', function (): void {
    phaseTenProvider($this, MockProviderMode::Unknown);
    phaseTenReadyUser($this, '100');
    $order = phaseTenIssue($this);
    $card = new ProviderCardDTO('MOCK-MISMATCHED-CARD', '', 'TEST •••• 1234', '1234', 8, 2029, 'USD', 'normal', true, '19.99000000');
    $result = new ProviderOperationDTO($order->provider_request_id, ProviderOperationStatus::Succeeded, $card->providerCardId, null, $card);

    $applied = app(ApplyCardIssueResultAction::class)->succeed($this->tenant->id, $order->id, $result);

    expect($applied->status)->toBe(CardIssueStatus::Unknown)
        ->and(phaseTenAccount($this, LedgerAccountType::UserCardIssueHold)->balance)->toBe('5.00000000')
        ->and(phaseTenAccount($this, LedgerAccountType::UserCardFundingHold)->balance)->toBe('20.00000000')
        ->and(UserCard::query()->count())->toBe(0);
});

it('is idempotent and conflicts when the same request changes amount', function (): void {
    phaseTenReadyUser($this);
    $requestId = (string) Str::uuid();
    $first = phaseTenIssue($this, '20.00', $requestId);
    $again = phaseTenIssue($this, '20.0', $requestId);

    expect($again->id)->toBe($first->id)
        ->and(CardIssueOrder::query()->count())->toBe(1)
        ->and(UserCard::query()->count())->toBe(1)
        ->and(fn () => phaseTenIssue($this, '21.00', $requestId))->toThrow(DomainException::class, 'different Card details');
});

it('resolves an unknown issue through the same provider request exactly once', function (MockProviderMode $queryMode, CardIssueStatus $expected): void {
    phaseTenProvider($this, MockProviderMode::Unknown);
    phaseTenReadyUser($this);
    $order = phaseTenIssue($this);
    expect($order->status)->toBe(CardIssueStatus::Unknown);

    phaseTenProvider($this, $queryMode);
    $resolved = app(SyncCardIssueAction::class)->execute($this->tenant->id, $order->id, $this->user->id);
    $again = app(SyncCardIssueAction::class)->execute($this->tenant->id, $order->id, $this->user->id);
    expect($resolved->status)->toBe($expected)
        ->and($again->status)->toBe($expected)
        ->and(CardIssueOrder::query()->count())->toBe(1)
        ->and(UserCard::query()->count())->toBe($expected === CardIssueStatus::Succeeded ? 1 : 0)
        ->and(LedgerEntry::query()->whereIn('event_type', ['CARD_ISSUE_FEE_SETTLE', 'CARD_ISSUE_FEE_RELEASE'])->count())->toBe(1);
})->with([
    'success' => [MockProviderMode::DelayedSuccess, CardIssueStatus::Succeeded],
    'failure' => [MockProviderMode::DelayedFailure, CardIssueStatus::Failed],
]);

it('continues trusted recovery after later User and Tenant suspension', function (): void {
    phaseTenProvider($this, MockProviderMode::Unknown);
    phaseTenReadyUser($this);
    $order = phaseTenIssue($this);
    $this->user->forceFill(['status' => 'SUSPENDED'])->save();
    $this->tenant->forceFill(['status' => 'SUSPENDED', 'suspended_at' => now()])->save();
    phaseTenProvider($this, MockProviderMode::DelayedSuccess);

    expect(app(SyncCardIssueAction::class)->execute($this->tenant->id, $order->id, $this->user->id)->status)
        ->toBe(CardIssueStatus::Succeeded)
        ->and(UserCard::query()->count())->toBe(1);
});

it('never duplicates a card or settlement while processing the same success result', function (): void {
    phaseTenProvider($this, MockProviderMode::Unknown);
    phaseTenReadyUser($this);
    $order = phaseTenIssue($this);
    $card = new ProviderCardDTO('MOCK-DUPLICATE-CARD', '', 'TEST •••• 1234', '1234', 8, 2029, 'USD', 'normal', true, '20.00000000');
    $result = new ProviderOperationDTO($order->provider_request_id, ProviderOperationStatus::Succeeded, $card->providerCardId, null, $card);
    app(ApplyCardIssueResultAction::class)->succeed($this->tenant->id, $order->id, $result);
    app(ApplyCardIssueResultAction::class)->succeed($this->tenant->id, $order->id, $result);

    expect(UserCard::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->where('event_type', 'CARD_ISSUE_FEE_SETTLE')->count())->toBe(1)
        ->and(LedgerEntry::query()->where('event_type', 'CARD_INITIAL_LOAD_SETTLE')->count())->toBe(1);
});

it('enforces max cards and tenant-scoped issue recovery', function (): void {
    phaseTenReadyUser($this, '100');
    $config = TenantCardProductConfig::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $config->forceFill(['max_cards_per_user' => 1])->save();
    $order = phaseTenIssue($this);

    expect(fn () => phaseTenIssue($this, requestId: (string) Str::uuid()))->toThrow(DomainException::class, 'maximum')
        ->and(fn () => app(SyncCardIssueAction::class)->execute(Tenant::query()->where('slug', 'tenant-b')->value('id'), $order->id, $this->user->id))->toThrow(ModelNotFoundException::class);
});

it('reserves max-card capacity while an issue outcome remains unresolved', function (): void {
    phaseTenProvider($this, MockProviderMode::Unknown);
    phaseTenReadyUser($this, '100');
    TenantCardProductConfig::query()->where('tenant_id', $this->tenant->id)
        ->where('card_product_id', $this->product->id)
        ->update(['max_cards_per_user' => 1]);

    expect(phaseTenIssue($this)->status)->toBe(CardIssueStatus::Unknown)
        ->and(fn () => phaseTenIssue($this, requestId: (string) Str::uuid()))
        ->toThrow(DomainException::class, 'maximum')
        ->and(CardIssueOrder::query()->count())->toBe(1);
});

it('never exposes injected PAN or CVV through storage audit or the normal Inertia page', function (): void {
    phaseTenReadyUser($this);
    phaseTenIssue($this);
    $serialized = UserCard::query()->get()->toJson().' '.AuditLog::query()->get()->toJson();
    $queuedPayloads = json_encode(Queue::pushedJobs(), JSON_THROW_ON_ERROR);
    $logs = collect(glob(storage_path('logs/*.log')) ?: [])
        ->map(fn (string $path): string => file_get_contents($path))
        ->implode("\n");

    expect($serialized)->not->toContain('TEST-MOCK-FULL-PAN-1234', 'TEST-MOCK-CVV')
        ->and($queuedPayloads)->not->toContain('TEST-MOCK-FULL-PAN-1234', 'TEST-MOCK-CVV')
        ->and($logs)->not->toContain('TEST-MOCK-FULL-PAN-1234', 'TEST-MOCK-CVV')
        ->and(UserCard::query()->firstOrFail()->masked_pan)->toBe('TEST •••• 1234');
    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/cards')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('user/Cards')->where('cards.0.maskedPan', 'TEST •••• 1234')->missing('cards.0.providerCardId'));
});

it('exposes only read-only tenant and platform card operations routes', function (): void {
    phaseTenReadyUser($this);
    phaseTenIssue($this);
    $platform = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();

    $this->actingAs($this->owner, 'tenant_admin')->get('http://a.localhost/admin/cards')->assertOk()->assertInertia(fn (Assert $page) => $page->component('tenant-admin/Cards')->has('cards', 1));
    $this->actingAs($platform, 'platform_admin')->get('http://admin.localhost/platform/cards')->assertOk()->assertInertia(fn (Assert $page) => $page->component('platform/Cards')->has('cards', 1));
    $uris = collect(Route::getRoutes())->map(fn ($route): string => implode('|', $route->methods()).' '.$route->uri());
    expect($uris->filter(fn (string $route): bool => preg_match('/cards.*(manual|success|settle|release|balance|reveal|freeze|cancel|reload)/i', $route) === 1)->all())->toBe([]);
});

it('enforces issue financial state and terminal immutability at the database boundary', function (): void {
    phaseTenReadyUser($this);
    $order = phaseTenIssue($this);

    expect(fn () => DB::transaction(function () use ($order): void {
        DB::table('card_issue_orders')->where('id', $order->id)->update(['status' => 'FAILED']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(function () use ($order): void {
            DB::table('user_cards')->where('card_issue_order_id', $order->id)->update(['provider_card_id' => 'XR-CONFLICTING-CARD']);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(function () use ($order): void {
            DB::table('user_cards')->where('card_issue_order_id', $order->id)->update(['masked_pan' => '4111111111111111']);
        }))->toThrow(QueryException::class);

    expect(DB::getSchemaBuilder()->hasColumns('user_cards', ['pan', 'card_number', 'card_no', 'cvv']))->toBeFalse();
});
