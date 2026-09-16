<?php

use App\Application\Card\ApplyCardIssueResultAction;
use App\Application\Card\ArchiveClearedUserCardAction;
use App\Application\Card\CreateCardIssueAction;
use App\Application\Card\DTOs\CardManagementInput;
use App\Application\Card\ManageCardAction;
use App\Application\Card\ProcessCardNotificationAction;
use App\Application\Card\ReceiveCardNotificationAction;
use App\Application\Card\RecordCardTransactionsAction;
use App\Application\Card\RefreshManagedCardAction;
use App\Application\Card\SubmitProviderCardholderAction;
use App\Application\Card\SyncCardIssueAction;
use App\Application\Card\SyncProviderCardholderAction;
use App\Application\Card\SyncUserCardTransactionsAction;
use App\Application\Card\UserCardCenterQuery;
use App\Application\Card\UserCardholderDetailsQuery;
use App\Application\Card\UserCardManagementAction;
use App\Application\Card\UserCardOverviewQuery;
use App\Application\Card\UserCardTransactionsQuery;
use App\Application\CardProduct\CardProductCatalogQuery;
use App\Application\CardProduct\CreateCardProductAction;
use App\Application\CardProduct\UpdateCardProductAction;
use App\Application\CardProviderDirectory\SaveCardProviderReferenceAction;
use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\SecurityDeposit\FundSecurityDepositAction;
use App\Application\SecurityDeposit\RefundSecurityDepositAction;
use App\Application\Tenant\UpdateTenantBusinessSettingsAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Application\Wallet\WalletActivityQuery;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Card\Enums\CardIssueStatus;
use App\Domain\Card\Enums\ProviderCardholderStatus;
use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\CardManagementOrder;
use App\Domain\Card\Models\CardProviderEvent;
use App\Domain\Card\Models\CardTransaction;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\Card\Models\UserCard;
use App\Domain\Card\Services\CardholderMaterials;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProduct\Models\TenantCardProductConfig;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\CardProvider\DTOs\ProviderCardDTO;
use App\Domain\CardProvider\DTOs\ProviderCardFundsDTO;
use App\Domain\CardProvider\DTOs\ProviderCardholderDTO;
use App\Domain\CardProvider\DTOs\ProviderCardQuoteDTO;
use App\Domain\CardProvider\DTOs\ProviderCardTransactionDTO;
use App\Domain\CardProvider\DTOs\ProviderOperationDTO;
use App\Domain\CardProvider\DTOs\ProviderSensitiveCardDTO;
use App\Domain\CardProvider\DTOs\ProviderTransactionPageDTO;
use App\Domain\CardProvider\Enums\MockProviderMode;
use App\Domain\CardProvider\Enums\ProviderCardholderReviewStatus;
use App\Domain\CardProvider\Enums\ProviderOperationStatus;
use App\Domain\CardProvider\Exceptions\ProviderRejectedException;
use App\Domain\CardProvider\Exceptions\ProviderUnknownResultException;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\SecurityDeposit\Models\SecurityDepositRefundRequest;
use App\Domain\SecurityDeposit\Services\RefundCardPolicy;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use App\Infrastructure\Providers\Card\MockCardProvider;
use App\Support\Errors\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

it('prefills only owned card editable fields and confirmed holder changes without leaking private materials', function (): void {
    $this->holderMaterials = ['fields' => [
        'email' => 'original@example.test', 'date_of_birth' => '1990-01-02',
        'nationality_country_code' => 'MY', 'mobile' => '13800138000', 'mobile_prefix' => '86',
        'residential_country_code' => 'MY', 'residential_state' => 'Selangor', 'residential_city' => 'Petaling Jaya',
        'residential_address' => 'Original street', 'residential_postal_code' => '46000',
        'legal_first_name' => 'PRIVATE-NAME', 'identity_number' => 'PRIVATE-IDENTITY',
    ], 'documents' => ['front' => 'PRIVATE-OBJECT-KEY']];
    [$card, $provider, $action] = managedCardFixture($this);
    $original = $this->holder->materials_encrypted;
    $entries = LedgerEntry::query()->count();
    $url = 'http://a.localhost/cards/'.$card->id.'/management';
    $read = fn () => $this->actingAs($this->user, 'tenant_user')->postJson($url, ['action' => 'holder_details']);
    $response = $read()->assertOk()->assertJsonPath('fields.email', 'original@example.test')
        ->assertJsonPath('fields.mobile_country_code', 'CN')->assertJsonCount(10, 'fields');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect($response->getContent())->not->toContain('PRIVATE-', 'documents', 'identity_number', 'provider_cardholder_id');
    $provider->shouldReceive('editCardholderFields')->once()->andReturnNull();
    $action->operate($this->tenant->id, $this->user->id, $card->id, (string) Str::uuid(), 'HOLDER_UPDATE', holderFields: ['email' => 'confirmed@example.test']);
    $read()->assertOk()->assertJsonPath('fields.email', 'confirmed@example.test')->assertJsonPath('fields.residential_address', 'Original street');
    $provider->shouldReceive('editCardholderFields')->once()->andThrow(new ProviderRejectedException('Rejected'));
    $action->operate($this->tenant->id, $this->user->id, $card->id, (string) Str::uuid(), 'HOLDER_UPDATE', holderFields: ['email' => 'failed@example.test']);
    $provider->shouldReceive('editCardholderFields')->once()->andThrow(new ProviderUnknownResultException('Unconfirmed'));
    $action->operate($this->tenant->id, $this->user->id, $card->id, (string) Str::uuid(), 'HOLDER_UPDATE', holderFields: ['email' => 'unknown@example.test']);
    $read()->assertOk()->assertJsonPath('fields.email', 'confirmed@example.test');
    expect($this->holder->fresh()->materials_encrypted)->toBe($original)
        ->and(LedgerEntry::query()->count())->toBe($entries)
        ->and(json_encode(app(UserCardCenterQuery::class)->get($this->tenant->id, $this->user->id)))->not->toContain('original@example.test', 'confirmed@example.test', 'PRIVATE-');
    $other = User::query()->where('tenant_id', '!=', $this->tenant->id)->firstOrFail();
    $this->actingAs($other, 'tenant_user')->postJson('http://b.localhost/cards/'.$card->id.'/management', ['action' => 'holder_details'])->assertNotFound();
    expect(fn () => app(UserCardholderDetailsQuery::class)->execute($this->tenant->id, $other->id, $card->id))->toThrow(ModelNotFoundException::class);
});

it('fails safely when original holder materials are unavailable rather than borrowing account information', function (): void {
    [$card] = managedCardFixture($this);
    $this->actingAs($this->user, 'tenant_user')->postJson('http://a.localhost/cards/'.$card->id.'/management', ['action' => 'holder_details'])
        ->assertStatus(503)->assertJsonMissingPath('fields');
});

function managedCardFixture($test, ?Closure $cardRead = null): array
{
    phaseTenReadyUser($test);
    phaseTenIssue($test);
    $card = UserCard::query()->where('tenant_id', $test->tenant->id)->where('user_id', $test->user->id)->firstOrFail();
    $card->forceFill(['provider_status' => 'normal'])->save();
    $provider = Mockery::mock(CardProviderInterface::class);
    $provider->shouldReceive('available')->andReturn(true);
    $provider->shouldReceive('name')->andReturn('PHOTONPAY');
    $provider->shouldReceive('getCard')->andReturnUsing($cardRead ?? fn () => new ProviderCardDTO($card->provider_card_id, '', $card->masked_pan, $card->last4, 8, 2029, 'USD', 'normal', false, '20.00000000'));
    app()->instance(CardProviderInterface::class, $provider);

    return [$card, $provider, app(ManageCardAction::class)];
}

it('manages card reload with quoted fee exact hold and one settlement', function (): void {
    [$card,$provider,$action] = managedCardFixture($this);
    $before = phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance;
    $provider->shouldReceive('quoteCardLoad')->once()->andReturnUsing(fn ($id, $amount, $request) => new ProviderCardQuoteDTO($request, '21.00000000', '20.00000000', '1.00000000'));
    $provider->shouldReceive('confirmCardLoad')->once()->andReturnUsing(fn ($id, $request) => new ProviderCardFundsDTO(ProviderOperationStatus::Succeeded, $id, $request, 'TX-LOAD', '21.00000000', '20.00000000', '1.00000000'));
    $request = (string) Str::uuid();
    $order = $action->quote($this->tenant->id, $this->user->id, $card->id, $request, '20');
    expect($order->status)->toBe('QUOTED')->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe($before);
    expect($action->quote($this->tenant->id, $this->user->id, $card->id, $request, '20')->id)->toBe($order->id);
    $result = $action->confirmLoad($this->tenant->id, $this->user->id, $card->id, $order->id);
    expect($result->status)->toBe('SUCCEEDED')
        ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe(Money::of($before, 'USDT')->subtract(Money::of('21', 'USDT'))->amount());
    expect($action->confirmLoad($this->tenant->id, $this->user->id, $card->id, $order->id)->status)->toBe('SUCCEEDED');
    expect(LedgerEntry::query()->where('reference_id', $order->id)->count())->toBe(2);
    $activity = app(WalletActivityQuery::class)->get($this->tenant->id, $this->user->id);
    $rows = collect($activity)->where('reference', $order->id)->values();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['amount'])->toBe('-21.00000000')
        ->and($rows[0]['state'])->toBe('Completed')
        ->and($rows[0]['eventType'])->toBe('CARD_LOAD_SETTLE')
        ->and($rows[0]['steps'])->toHaveCount(2);
    $other = User::query()->where('tenant_id', '<>', $this->tenant->id)->firstOrFail();
    expect(app(WalletActivityQuery::class)->get($this->tenant->id, $other->id))->toBe([]);
    DB::statement('SET CONSTRAINTS card_management_accounting IMMEDIATE');
});

it('confirms reload without a second password or checkbox and replays without another debit', function (bool $unknown): void {
    [$card, $provider] = managedCardFixture($this);
    $provider->shouldReceive('quoteCardLoad')->once()->andReturnUsing(fn ($id, $amount, $request) => new ProviderCardQuoteDTO($request, '21.00000000', '20.00000000', '1.00000000'));
    $confirmation = $provider->shouldReceive('confirmCardLoad')->once();
    if ($unknown) {
        $confirmation->andThrow(new ProviderUnknownResultException('Unconfirmed'));
    } else {
        $confirmation->andReturnUsing(fn ($id, $request) => new ProviderCardFundsDTO(ProviderOperationStatus::Succeeded, $id, $request, 'TX-ONE-SUBMIT', '21.00000000', '20.00000000', '1.00000000'));
    }
    $this->actingAs($this->user, 'tenant_user');
    $url = 'http://a.localhost/cards/'.$card->id.'/management';
    $before = phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance;
    $quote = $this->postJson($url, ['action' => 'quote', 'request_id' => (string) Str::uuid(), 'amount' => '20'])->assertOk()->assertJsonPath('state', 'quoted')->json();
    expect(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe($before);
    $input = ['action' => 'confirm', 'order_id' => $quote['id']];
    $this->postJson($url, $input)->assertOk()->assertJsonPath('state', $unknown ? 'confirming' : 'completed');
    $entries = LedgerEntry::query()->count();
    $this->postJson($url, $input)->assertOk()->assertJsonPath('state', $unknown ? 'confirming' : 'completed');
    expect(LedgerEntry::query()->count())->toBe($entries)
        ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe(Money::of($before, 'USDT')->subtract(Money::of('21', 'USDT'))->amount());
    $this->postJson($url, ['action' => 'return', 'request_id' => (string) Str::uuid(), 'amount' => '20'])->assertUnprocessable()->assertJsonValidationErrors(['current_password', 'confirmed']);
    $this->postJson($url, ['action' => 'reveal'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
})->with([false, true]);

it('keeps uncertain reload held blocks a new request and recovers only by stable query', function (): void {
    [$card,$provider,$action] = managedCardFixture($this);
    $provider->shouldReceive('quoteCardLoad')->once()->andReturnUsing(fn ($id, $amount, $request) => new ProviderCardQuoteDTO($request, '20.00000000', '20.00000000', '0.00000000'));
    $provider->shouldReceive('confirmCardLoad')->once()->andThrow(new ProviderUnknownResultException('Unconfirmed'));
    $provider->shouldReceive('queryCardFunds')->once()->andReturnUsing(fn ($id, $request, $kind) => new ProviderCardFundsDTO(ProviderOperationStatus::Succeeded, $id, $request, 'TX-RECOVERY', '20.00000000', '20.00000000', '0.00000000'));
    $order = $action->quote($this->tenant->id, $this->user->id, $card->id, (string) Str::uuid(), '20');
    expect($action->confirmLoad($this->tenant->id, $this->user->id, $card->id, $order->id)->status)->toBe('UNKNOWN');
    $overview = app(UserCardOverviewQuery::class);
    expect($overview->get($this->tenant->id, $this->user->id)['pending'])->toBe(1);
    $this->actingAs($this->user, 'tenant_user')->postJson('http://a.localhost/cards/'.$card->id.'/management', ['action' => 'history'])
        ->assertOk()->assertJsonPath('orders.0.id', $order->id)->assertJsonPath('orders.0.state', 'confirming');
    expect(fn () => $action->quote($this->tenant->id, $this->user->id, $card->id, (string) Str::uuid(), '20'))->toThrow(DomainException::class);
    expect($action->sync($this->tenant->id, $order->id)->status)->toBe('SUCCEEDED');
    expect($overview->get($this->tenant->id, $this->user->id)['pending'])->toBe(0);
    DB::statement('SET CONSTRAINTS card_management_accounting IMMEDIATE');
});

it('returns only confirmed net card funds and never replays a return', function (): void {
    [$card,$provider,$action] = managedCardFixture($this);
    $before = phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance;
    $provider->shouldReceive('returnCardFunds')->once()->andReturnUsing(fn ($id, $amount, $request) => new ProviderCardFundsDTO(ProviderOperationStatus::Succeeded, $id, $request, 'TX-RETURN', '10.00000000', '9.00000000', '1.00000000'));
    $id = (string) Str::uuid();
    $order = $action->operate($this->tenant->id, $this->user->id, $card->id, $id, 'RETURN', '10');
    expect($order->status)->toBe('SUCCEEDED')->and($order->arrival_amount)->toBe('9.00000000')
        ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe(Money::of($before, 'USDT')->add(Money::of('9', 'USDT'))->amount());
    expect($action->operate($this->tenant->id, $this->user->id, $card->id, $id, 'RETURN', '10')->id)->toBe($order->id);
    expect(fn () => $action->operate($this->tenant->id, $this->user->id, $card->id, $id, 'RETURN', '11'))->toThrow(DomainException::class);
    DB::statement('SET CONSTRAINTS card_management_accounting IMMEDIATE');
});

it('releases a reload hold only on a definitive rejection', function (): void {
    [$card,$provider,$action] = managedCardFixture($this);
    $before = phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance;
    $provider->shouldReceive('quoteCardLoad')->once()->andReturnUsing(fn ($id, $amount, $request) => new ProviderCardQuoteDTO($request, '20.00000000', '20.00000000', '0.00000000'));
    $provider->shouldReceive('confirmCardLoad')->once()->andThrow(new ProviderRejectedException('Declined'));
    $order = $action->quote($this->tenant->id, $this->user->id, $card->id, (string) Str::uuid(), '20');
    $result = $action->confirmLoad($this->tenant->id, $this->user->id, $card->id, $order->id);
    expect($result->status)->toBe('FAILED')->and($result->release_entry_id)->not->toBeNull()
        ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe($before);
    $row = collect(app(WalletActivityQuery::class)->get($this->tenant->id, $this->user->id))->firstWhere('reference', $order->id);
    expect($row['amount'])->toBe('0.00000000')->and($row['state'])->toBe('Returned')
        ->and($row['steps'])->toHaveCount(2);
    DB::statement('SET CONSTRAINTS card_management_accounting IMMEDIATE');
});

it('requires owner scope and password before revealing ephemeral card number and CVV', function (): void {
    [$card,$provider] = managedCardFixture($this);
    $provider->shouldReceive('revealCard')->once()->andReturn(new ProviderSensitiveCardDTO('411111111111'.$card->last4, '987', false, '08/29'));
    $this->actingAs($this->user, 'tenant_user');
    $url = 'http://a.localhost/cards/'.$card->id.'/management';
    $this->postJson($url, ['action' => 'reveal', 'current_password' => 'incorrect'])->assertUnprocessable();
    $this->postJson($url, ['action' => 'reveal', 'current_password' => 'local-password'])->assertOk()->assertExactJson(['pan' => '411111111111'.$card->last4, 'cvv' => '987'])->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    $this->postJson('http://b.localhost/cards/'.$card->id.'/management', ['action' => 'reveal', 'current_password' => 'local-password'])->assertRedirect();
    expect(json_encode($card->fresh()))->not->toContain('987', '411111111111');
});

it('rejects malformed or mismatched card numbers before revealing sensitive information', function (string $pan): void {
    [$card, $provider] = managedCardFixture($this);
    $provider->shouldReceive('revealCard')->once()->andReturn(new ProviderSensitiveCardDTO($pan, '987', false));
    $this->actingAs($this->user, 'tenant_user')->postJson('http://a.localhost/cards/'.$card->id.'/management', [
        'action' => 'reveal', 'current_password' => 'local-password',
    ])->assertStatus(503)->assertJsonMissingPath('pan')->assertJsonMissingPath('cvv');
})->with(['masked' => ['************1234'], 'too short' => ['1234'], 'wrong card' => ['4111111111119999']]);

it('verifies notifications deduplicates them and synchronizes card truth without changing wallet', function (int $keyBits): void {
    [$card,$provider] = managedCardFixture($this);
    $before = phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance;
    $provider->shouldReceive('getTransaction')->once()->with($card->provider_card_id, 'TX-CONSUMPTION')->andReturn(new ProviderCardTransactionDTO('TX-CONSUMPTION', '3.00000000', 'USD', 'purchase', 'completed', '2026-09-11T12:00:00', 'A shop'));
    $key = openssl_pkey_new(['private_key_bits' => $keyBits]);
    config(['card-provider.photonpay.webhook_public_key' => openssl_pkey_get_details($key)['key']]);
    $body = json_encode(['cardId' => $card->provider_card_id, 'transactionId' => 'TX-CONSUMPTION', 'cardBalance' => '99999', 'tenant_id' => (string) Str::uuid()]);
    openssl_sign($body, $signature, $key, OPENSSL_ALGO_MD5);
    $receive = app(ReceiveCardNotificationAction::class);
    expect(fn () => $receive->execute($body, 'invalid', 'issuing', 'auth'))->toThrow(DomainException::class);
    $this->call('POST', 'http://unknown-callback-host.example/webhooks/card-provider', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_PD_SIGN' => base64_encode($signature),
        'HTTP_X_PD_NOTIFICATION_CATAGORY' => 'issuing', 'HTTP_X_PD_NOTIFICATION_TYPE' => 'auth',
    ], $body)->assertOk()->assertExactJson(['roger' => true]);
    $receive->execute($body, base64_encode($signature), 'issuing', 'auth');
    $receive->execute($body, base64_encode($signature), 'issuing', 'auth');
    $event = CardProviderEvent::query()->firstOrFail();
    expect(CardProviderEvent::query()->count())->toBe(1)->and($event->tenant_id)->toBe($this->tenant->id);
    app(ProcessCardNotificationAction::class)->execute($event->tenant_id, $event->id);
    expect($event->fresh()->status)->toBe('PROCESSED')->and($card->fresh()->provider_balance)->toBe('20.00000000')
        ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe($before)
        ->and(CardTransaction::query()->count())->toBe(1);
    app(ProcessCardNotificationAction::class)->execute($event->tenant_id, $event->id);
})->with([1024, 2048]);

it('retries holder notifications when the provider lookup preserves stale ready state', function (): void {
    [$card, $provider] = managedCardFixture($this);
    $this->holder->forceFill(['synced_at' => now()->subMinute()])->save();
    $provider->shouldReceive('getCardholder')->once()->andThrow(new ProviderUnknownResultException('Unconfirmed'));
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    config(['card-provider.photonpay.webhook_public_key' => openssl_pkey_get_details($key)['key']]);
    $body = json_encode(['cardholderId' => $this->holder->provider_cardholder_id]);
    openssl_sign($body, $signature, $key, OPENSSL_ALGO_MD5);
    app(ReceiveCardNotificationAction::class)->execute($body, base64_encode($signature), 'issuing', 'cardholder_status_update');
    $event = CardProviderEvent::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    app(ProcessCardNotificationAction::class)->execute($event->tenant_id, $event->id);
    expect($event->fresh()->status)->toBe('RETRY')->and($event->fresh()->processed_at)->toBeNull();
    $provider->shouldReceive('getCardholder')->once()->andReturn(new ProviderCardholderDTO($this->holder->provider_cardholder_id, ProviderCardholderReviewStatus::Ready, 'normal', 'passed'));
    app(ProcessCardNotificationAction::class)->execute($event->tenant_id, $event->id);
    expect($event->fresh()->status)->toBe('PROCESSED')->and($event->fresh()->attempts)->toBe(2);
});

it('settles cancellation automatic return once without changing the security deposit', function (): void {
    [$card,$provider,$action] = managedCardFixture($this);
    $before = phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance;
    $deposit = phaseTenAccount($this, LedgerAccountType::UserSecurityDeposit)->balance;
    $provider->shouldReceive('cancelCard')->once()->andReturnUsing(fn ($id, $request) => new ProviderOperationDTO($request, ProviderOperationStatus::Processing, $id));
    $cancel = $action->operate($this->tenant->id, $this->user->id, $card->id, (string) Str::uuid(), 'CANCEL');
    expect($cancel->status)->toBe('UNKNOWN');
    $provider->shouldReceive('queryCardFunds')->twice()->with($card->provider_card_id, 'TX-CANCEL-RETURN', 'CANCEL_RETURN')
        ->andReturn(new ProviderCardFundsDTO(ProviderOperationStatus::Succeeded, $card->provider_card_id, 'TX-CANCEL-RETURN', 'TX-CANCEL-RETURN', '20.00000000', '19.00000000', '1.00000000'));
    $result = $action->settleCancellationReturn($this->tenant->id, $this->user->id, $card->id, 'TX-CANCEL-RETURN');
    expect($result->status)->toBe('SUCCEEDED');
    expect($action->settleCancellationReturn($this->tenant->id, $this->user->id, $card->id, 'TX-CANCEL-RETURN')->id)->toBe($result->id);
    expect(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe(Money::of($before, 'USDT')->add(Money::of('19', 'USDT'))->amount())
        ->and(phaseTenAccount($this, LedgerAccountType::UserSecurityDeposit)->balance)->toBe($deposit)
        ->and(LedgerEntry::query()->where('reference_id', $result->id)->count())->toBe(1);
    DB::statement('SET CONSTRAINTS card_management_accounting IMMEDIATE');
});

it('blocks mismatched return evidence without crediting or releasing anything', function (): void {
    [$card,$provider,$action] = managedCardFixture($this);
    $before = phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance;
    $provider->shouldReceive('returnCardFunds')->once()->andReturnUsing(fn ($id, $amount, $request) => new ProviderCardFundsDTO(ProviderOperationStatus::Succeeded, 'FOREIGN-CARD', $request, 'TX-WRONG', '10.00000000', '9.00000000', '1.00000000'));
    $result = $action->operate($this->tenant->id, $this->user->id, $card->id, (string) Str::uuid(), 'RETURN', '10');
    expect($result->status)->toBe('UNKNOWN')->and($result->settlement_entry_id)->toBeNull()
        ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe($before);
});

it('expires unused quotations without calling recharge or creating holds', function (): void {
    [$card,$provider,$action] = managedCardFixture($this);
    $provider->shouldReceive('quoteCardLoad')->once()->andReturnUsing(fn ($id, $amount, $request) => new ProviderCardQuoteDTO($request, '20.00000000', '20.00000000', '0.00000000'));
    $order = $action->quote($this->tenant->id, $this->user->id, $card->id, (string) Str::uuid(), '20');
    $this->travel(31)->seconds();
    expect($action->confirmLoad($this->tenant->id, $this->user->id, $card->id, $order->id)->status)->toBe('EXPIRED')
        ->and(LedgerEntry::query()->where('reference_id', $order->id)->count())->toBe(0);
});

it('encrypts holder changes and recovers unconfirmed edits by readback not replay', function (): void {
    [$card,$provider,$action] = managedCardFixture($this);
    $original = $this->holder->materials_encrypted;
    $provider->shouldReceive('editCardholderFields')->once()->andThrow(new ProviderUnknownResultException('Unconfirmed'));
    $provider->shouldReceive('cardholderFieldsMatch')->once()->andReturn(true);
    $order = $action->operate($this->tenant->id, $this->user->id, $card->id, (string) Str::uuid(), 'HOLDER_UPDATE', null, ['email' => 'new-holder@example.test']);
    expect($order->status)->toBe('UNKNOWN')->and(json_encode($order))->not->toContain('new-holder@example.test', 'holder_changes_encrypted')
        ->and($order->holder_changes_encrypted)->not->toContain('new-holder@example.test');
    expect($action->sync($this->tenant->id, $order->id)->status)->toBe('SUCCEEDED')
        ->and($this->holder->fresh()->materials_encrypted)->toBe($original);
});

it('enforces card owner and money confirmation even when tenant host matches', function (): void {
    [$card,$provider] = managedCardFixture($this);
    $other = $this->user->replicate(['account_id']);
    $other->forceFill(['email' => 'management-other@example.test'])->save();
    $this->actingAs($other, 'tenant_user')->postJson('http://a.localhost/cards/'.$card->id.'/management', ['action' => 'refresh'])->assertNotFound();
    $this->actingAs($this->user, 'tenant_user')->postJson('http://a.localhost/cards/'.$card->id.'/management', ['action' => 'cancel', 'request_id' => (string) Str::uuid(), 'current_password' => 'local-password'])->assertUnprocessable();
    expect(CardManagementOrder::query()->count())->toBe(0);
});

it('rejects overwritten terminal management history in PostgreSQL', function (): void {
    [$card,$provider,$action] = managedCardFixture($this);
    $provider->shouldReceive('returnCardFunds')->andReturnUsing(fn ($id, $amount, $request) => new ProviderCardFundsDTO(ProviderOperationStatus::Succeeded, $id, $request, 'TX-SEALED', '10.00000000', '10.00000000', '0.00000000'));
    $order = $action->operate($this->tenant->id, $this->user->id, $card->id, (string) Str::uuid(), 'RETURN', '10');
    expect(fn () => DB::transaction(fn () => DB::table('card_management_orders')->where('tenant_id', $this->tenant->id)->where('id', $order->id)->update(['arrival_amount' => '99'])))->toThrow(QueryException::class);
});

it('lets a paid card operation settle after user suspension', function (): void {
    [$card,$provider,$action] = managedCardFixture($this);
    $provider->shouldReceive('quoteCardLoad')->once()->andReturnUsing(fn ($id, $amount, $request) => new ProviderCardQuoteDTO($request, '20.00000000', '20.00000000', '0.00000000'));
    $provider->shouldReceive('confirmCardLoad')->once()->andThrow(new ProviderUnknownResultException('Unconfirmed'));
    $provider->shouldReceive('queryCardFunds')->once()->andReturnUsing(fn ($id, $request, $kind) => new ProviderCardFundsDTO(ProviderOperationStatus::Succeeded, $id, $request, 'TX-SUSPENDED', '20.00000000', '20.00000000', '0.00000000'));
    $order = $action->quote($this->tenant->id, $this->user->id, $card->id, (string) Str::uuid(), '20');
    $action->confirmLoad($this->tenant->id, $this->user->id, $card->id, $order->id);
    $this->user->forceFill(['status' => 'SUSPENDED'])->save();
    expect($action->sync($this->tenant->id, $order->id)->status)->toBe('SUCCEEDED');
    DB::statement('SET CONSTRAINTS card_management_accounting IMMEDIATE');
});

it('rejects a late balance response after a newer refresh has completed', function (): void {
    $card = null;
    $calls = 0;
    [$card] = managedCardFixture($this, function () use (&$card, &$calls) {
        $calls++;
        if ($calls === 1) {
            app(RefreshManagedCardAction::class)->execute($this->tenant->id, $this->user->id, $card->id);
            $balance = '18.00000000';
        } else {
            $balance = '15.00000000';
        }

        return new ProviderCardDTO($card->provider_card_id, '', $card->masked_pan, $card->last4, 8, 2029, 'USD', 'normal', false, $balance);
    });
    expect(fn () => app(RefreshManagedCardAction::class)->execute($this->tenant->id, $this->user->id, $card->id))->toThrow(DomainException::class);
    expect($card->fresh()->provider_balance)->toBe('15.00000000')->and($card->fresh()->refresh_generation)->toBe(2);
});

it('rejects unconfigured refund timing and client attempts to set the deadline or manually settle', function (): void {
    phaseTenReadyUser($this);
    $this->tenant->businessSettings()->update(['security_deposit_refund_wait_days' => null]);
    expect(fn () => app(RefundSecurityDepositAction::class)->request($this->tenant->id, $this->user->id, (string) Str::uuid()))->toThrow(DomainException::class);
    expect(SecurityDepositRefundRequest::query()->count())->toBe(0);
    $this->actingAs($this->user, 'tenant_user')->postJson('http://a.localhost/security-deposit/refund', [
        'action' => 'check', 'refund_id' => (string) Str::uuid(), 'current_password' => 'password', 'confirmed' => true,
        'refund_wait_days' => 0, 'refund_eligible_at' => now()->toIso8601String(),
    ])->assertUnprocessable()->assertJsonValidationErrors(['action', 'refund_wait_days', 'refund_eligible_at']);
});

it('keeps refund snapshots immutable and prevents early completion', function (): void {
    phaseTenReadyUser($this);
    $this->tenant->businessSettings()->update(['security_deposit_refund_wait_days' => 2]);
    $refund = app(RefundSecurityDepositAction::class)->request($this->tenant->id, $this->user->id, (string) Str::uuid());
    foreach ([['refund_wait_days' => 0, 'refund_eligible_at' => $refund->created_at],
        ['status' => 'COMPLETED', 'progress' => 'completed', 'completed_at' => now()]] as $mutation) {
        expect(fn () => DB::transaction(fn () => DB::table('security_deposit_refund_requests')->where('id', $refund->id)->update($mutation)))->toThrow(QueryException::class);
    }
    $this->artisan('deposits:process-refunds', ['--tenant' => (string) Str::uuid()])->assertSuccessful();
    expect($refund->fresh()->progress)->toBe('freezing');
    $this->artisan('deposits:process-refunds', ['--tenant' => $this->tenant->id])->assertSuccessful();
    expect($refund->fresh()->status)->toBe('CHECKING')->and($refund->fresh()->progress)->toBe('waiting')
        ->and(phaseTenAccount($this, LedgerAccountType::UserSecurityDeposit)->balance)->toBe('10.00000000');
});

it('retains refund principal until frozen or cancelled card status is authoritative', function (string $state, ?string $balance, bool $unknown): void {
    $card = null;
    $depth = DB::transactionLevel();
    [$card] = managedCardFixture($this, function () use (&$card, $state, $balance, $unknown, $depth) {
        expect(DB::transactionLevel())->toBe($depth);
        if ($unknown) {
            throw new ProviderUnknownResultException('Unconfirmed card lookup');
        }

        return new ProviderCardDTO($card->provider_card_id, '', $card->masked_pan, $card->last4, 8, 2029, 'USD', $state, false, $balance);
    });
    $action = app(RefundSecurityDepositAction::class);
    $refund = $action->request($this->tenant->id, $this->user->id, (string) Str::uuid());
    $action->settle($this->tenant->id, $this->user->id, $refund->id);
    expect($refund->fresh()->status)->toBe('CHECKING')
        ->and(phaseTenAccount($this, LedgerAccountType::UserSecurityDeposit)->balance)->toBe('10.00000000')
        ->and(LedgerEntry::query()->where('event_type', 'SECURITY_DEPOSIT_REFUND')->count())->toBe(0);
})->with([['normal', '0.00000000', false], ['cancelled', null, false], ['cancelled', '0.00000000', true]]);

it('snapshots company refund days freezes nonzero cards and blocks every action except transactions', function (): void {
    $state = 'normal';
    $card = null;
    [$card, $provider, $manage] = managedCardFixture($this, function () use (&$state, &$card) {
        return new ProviderCardDTO($card->provider_card_id, '', $card->masked_pan, $card->last4, 8, 2029, 'USD', $state, false, '20.00000000');
    });
    $provider->shouldReceive('freezeCard')->once()->andReturnUsing(function () use (&$state) {
        $state = 'frozen';

        return new ProviderOperationDTO('freeze', ProviderOperationStatus::Succeeded);
    });
    $provider->shouldReceive('unfreezeCard')->once()->andReturnUsing(function () use (&$state) {
        $state = 'normal';

        return new ProviderOperationDTO('unfreeze', ProviderOperationStatus::Succeeded);
    });
    $this->tenant->businessSettings()->update(['security_deposit_refund_wait_days' => 1]);
    $refunds = app(RefundSecurityDepositAction::class);
    $requestId = (string) Str::uuid();
    $refund = $refunds->request($this->tenant->id, $this->user->id, $requestId);
    $this->tenant->businessSettings()->update(['security_deposit_refund_wait_days' => 7]);
    expect($refunds->request($this->tenant->id, $this->user->id, $requestId)->id)->toBe($refund->id);
    $refunds->settle($this->tenant->id, $this->user->id, $refund->id);
    expect([$state, $card->fresh()->provider_status, CardManagementOrder::query()->where('card_id', $card->id)->get(['kind', 'status'])->toArray()])->toBe(['frozen', 'frozen', [['kind' => 'FREEZE', 'status' => 'SUCCEEDED']]]);
    expect($refund->fresh()->progress)->toBe('waiting')->and($refund->fresh()->refund_wait_days)->toBe(1)
        ->and($card->fresh()->provider_status)->toBe('frozen')
        ->and(phaseTenAccount($this, LedgerAccountType::UserSecurityDeposit)->balance)->toBe('10.00000000');
    $view = app(UserCardCenterQuery::class)->get($this->tenant->id, $this->user->id);
    expect($view['refundPending'])->toBeTrue()->and($view['cards'][0]['refundLocked'])->toBeTrue();
    foreach (['reveal', 'holder_details', 'history', 'refresh', 'sync', 'quote', 'confirm', 'holder', 'return', 'freeze', 'unfreeze', 'cancel'] as $action) {
        expect(fn () => app(UserCardManagementAction::class)->execute($this->tenant->id, $this->user->id, $card->id,
            CardManagementInput::fromValidated(['action' => $action])))->toThrow(DomainException::class);
    }
    expect(fn () => $manage->operate($this->tenant->id, $this->user->id, $card->id, (string) Str::uuid(), 'UNFREEZE'))->toThrow(DomainException::class);
    $refunds->cancel($this->tenant->id, $this->user->id, $refund->id);
    expect($refund->fresh()->status)->toBe('CHECKING');
    $refunds->settle($this->tenant->id, $this->user->id, $refund->id);
    expect($refund->fresh()->status)->toBe('CANCELLED')->and($state)->toBe('normal')
        ->and(LedgerEntry::query()->where('event_type', 'SECURITY_DEPOSIT_REFUND')->count())->toBe(0);
});

it('refunds automatically after the deadline without requiring a zero card balance and does not replay', function (): void {
    $state = 'normal';
    $card = null;
    [$card, $provider] = managedCardFixture($this, function () use (&$state, &$card) {
        return new ProviderCardDTO($card->provider_card_id, '', $card->masked_pan, $card->last4, 8, 2029, 'USD', $state, false, '20.00000000');
    });
    $provider->shouldReceive('freezeCard')->once()->andReturnUsing(function () use (&$state) {
        $state = 'frozen';

        return new ProviderOperationDTO('freeze', ProviderOperationStatus::Succeeded);
    });
    $action = app(RefundSecurityDepositAction::class);
    $refund = $action->request($this->tenant->id, $this->user->id, (string) Str::uuid());
    $action->settle($this->tenant->id, $this->user->id, $refund->id);
    $action->settle($this->tenant->id, $this->user->id, $refund->id);
    expect($refund->fresh()->status)->toBe('COMPLETED')->and($card->fresh()->provider_balance)->toBe('20.00000000')
        ->and(phaseTenAccount($this, LedgerAccountType::UserSecurityDeposit)->balance)->toBe('0.00000000')
        ->and(LedgerEntry::query()->where('event_type', 'SECURITY_DEPOSIT_REFUND')->count())->toBe(1);
    expect(RefundCardPolicy::blocked($this->tenant->id, $this->user->id, $card->id))->toBeTrue();
});

it('does not refund on a freeze timeout or replay it with another request and safely restores after cancellation', function (): void {
    $state = 'normal';
    $card = null;
    [$card, $provider] = managedCardFixture($this, function () use (&$state, &$card) {
        return new ProviderCardDTO($card->provider_card_id, '', $card->masked_pan, $card->last4, 8, 2029, 'USD', $state, false, '20.00000000');
    });
    $provider->shouldReceive('freezeCard')->once()->andThrow(new ProviderUnknownResultException('Timeout'));
    $provider->shouldReceive('unfreezeCard')->once()->andReturnUsing(function () use (&$state) {
        $state = 'normal';

        return new ProviderOperationDTO('unfreeze', ProviderOperationStatus::Succeeded);
    });
    $action = app(RefundSecurityDepositAction::class);
    $refund = $action->request($this->tenant->id, $this->user->id, (string) Str::uuid());
    $action->settle($this->tenant->id, $this->user->id, $refund->id);
    $action->settle($this->tenant->id, $this->user->id, $refund->id);
    expect($refund->fresh()->progress)->toBe('blocked');
    $action->cancel($this->tenant->id, $this->user->id, $refund->id);
    $action->settle($this->tenant->id, $this->user->id, $refund->id);
    expect($refund->fresh()->status)->toBe('CHECKING')
        ->and(fn () => $action->request($this->tenant->id, $this->user->id, (string) Str::uuid()))->toThrow(DomainException::class);
    $state = 'frozen';
    $action->settle($this->tenant->id, $this->user->id, $refund->id);
    expect([$state, CardManagementOrder::query()->where('card_id', $card->id)->get(['kind', 'status'])->toArray()])->toBe(['normal', [['kind' => 'FREEZE', 'status' => 'SUCCEEDED'], ['kind' => 'UNFREEZE', 'status' => 'SUCCEEDED']]]);
    expect($refund->fresh()->status)->toBe('CANCELLED')->and($state)->toBe('normal')
        ->and(LedgerEntry::query()->where('event_type', 'SECURITY_DEPOSIT_REFUND')->count())->toBe(0);
});

it('leaves originally frozen cards frozen on cancellation and never assigns a deadline to legacy requests', function (): void {
    $card = null;
    [$card, $provider] = managedCardFixture($this, function () use (&$card) {
        return new ProviderCardDTO($card->provider_card_id, '', $card->masked_pan, $card->last4, 8, 2029, 'USD', 'frozen', false, '20.00000000');
    });
    $provider->shouldNotReceive('freezeCard');
    $provider->shouldNotReceive('unfreezeCard');
    $this->tenant->businessSettings()->update(['security_deposit_refund_wait_days' => 3]);
    $action = app(RefundSecurityDepositAction::class);
    $refund = $action->request($this->tenant->id, $this->user->id, (string) Str::uuid());
    $action->settle($this->tenant->id, $this->user->id, $refund->id);
    $action->cancel($this->tenant->id, $this->user->id, $refund->id);
    $action->settle($this->tenant->id, $this->user->id, $refund->id);
    expect($refund->fresh()->status)->toBe('CANCELLED')->and($card->fresh()->provider_status)->toBe('frozen');
    $legacy = SecurityDepositRefundRequest::query()->create([
        'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'wallet_id' => Wallet::query()->where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->value('id'),
        'request_id' => (string) Str::uuid(), 'amount' => '10', 'asset_code' => 'USDT', 'status' => 'CHECKING',
    ]);
    $action->settle($this->tenant->id, $this->user->id, $legacy->id);
    expect($legacy->fresh()->status)->toBe('CHECKING')->and($legacy->fresh()->refund_eligible_at)->toBeNull();
    $action->cancel($this->tenant->id, $this->user->id, $legacy->id);
    expect($legacy->fresh()->status)->toBe('CANCELLED');
});

it('refunds a guarantee once after confirmed cancelled-card status without re-awarding commission', function (): void {
    [$card] = managedCardFixture($this);
    $provider = Mockery::mock(CardProviderInterface::class);
    $provider->shouldReceive('available')->andReturn(true);
    $provider->shouldReceive('getCard')->once()->andReturn(new ProviderCardDTO($card->provider_card_id, '', $card->masked_pan, $card->last4, 8, 2029, 'USD', 'cancelled', false, '0.00000000'));
    app()->instance(CardProviderInterface::class, $provider);
    $action = app(RefundSecurityDepositAction::class);
    $refund = $action->request($this->tenant->id, $this->user->id, (string) Str::uuid());
    $action->settle($this->tenant->id, $this->user->id, $refund->id);
    $action->settle($this->tenant->id, $this->user->id, $refund->id);
    expect($refund->fresh()->status)->toBe('COMPLETED')
        ->and(phaseTenAccount($this, LedgerAccountType::UserSecurityDeposit)->balance)->toBe('0.00000000')
        ->and(LedgerEntry::query()->where('event_type', 'SECURITY_DEPOSIT_REFUND')->count())->toBe(1);
});

beforeEach(function (): void {
    $this->seed();
    Storage::fake('private');
    Queue::fake();
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->tenant->businessSettings()->update(['security_deposit_refund_wait_days' => 0]);
    $this->user = User::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->owner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $this->product = CardProduct::query()->where('provider', 'PHOTONPAY')->firstOrFail();
    phaseTenProvider($this, MockProviderMode::Success, 'READY');
});

it('blocks unconfigured product setup and issue before uploads provider calls or holds', function (): void {
    phaseTenReadyUser($this);
    $owner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $product = app(CreateCardProductAction::class)->execute([
        'name' => 'No API', 'minimum_initial_load' => '20', 'opening_fee' => '5.00000000', 'minimum_reload' => '20', 'status' => 'ACTIVE',
    ], $owner);
    $config = TenantCardProductConfig::query()->where('tenant_id', $this->tenant->id)->where('card_product_id', $this->product->id)->sole()->replicate();
    $config->card_product_id = $product->id;
    $config->save();
    $this->product = $product;
    $entries = LedgerEntry::query()->count();
    $holders = ProviderCardholder::query()->count();
    $files = count(Storage::disk('private')->allFiles());
    $provider = Mockery::mock(CardProviderInterface::class);
    $provider->shouldReceive('available')->andReturn(true);
    $provider->shouldReceive('name')->andReturn('PHOTONPAY');
    $provider->shouldNotReceive('createCardholder');
    $provider->shouldNotReceive('issueCard');
    app()->instance(CardProviderInterface::class, $provider);
    expect(fn () => app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, independentCardMaterials($this)))
        ->toThrow(DomainException::class, 'currently unavailable');
    expect(fn () => phaseTenIssue($this))->toThrow(DomainException::class, 'currently unavailable');
    $catalog = app(CardProductCatalogQuery::class)->user($this->tenant->id, $this->user->id);
    $row = collect($catalog['products'])->firstWhere('id', $product->id);
    expect($row['readyForSetup'])->toBeFalse()->and($row['guidance'])->toBe('Card setup is currently unavailable.')
        ->and(LedgerEntry::query()->count())->toBe($entries)->and(CardIssueOrder::query()->count())->toBe(0)
        ->and(ProviderCardholder::query()->count())->toBe($holders)->and(count(Storage::disk('private')->allFiles()))->toBe($files);
});

it('preserves historical product routing while allowing non-routing configuration edits', function (): void {
    phaseTenReadyUser($this);
    $owner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $data = ['name' => 'Updated name', 'provider_product_ref' => $this->product->provider_product_ref,
        'minimum_initial_load' => '20', 'opening_fee' => '5.00000000', 'minimum_reload' => '20', 'status' => 'ACTIVE'];
    $action = app(UpdateCardProductAction::class);
    $action->execute($this->product->id, $data, $owner);
    expect(fn () => $action->execute($this->product->id, [...$data, 'provider_product_ref' => '123456'], $owner))
        ->toThrow(DomainException::class, 'card history');
    expect(fn () => DB::transaction(fn () => $this->product->forceFill(['provider' => 'UNCONFIGURED'])->save()))->toThrow(QueryException::class);
    expect($this->product->fresh()->provider)->toBe('PHOTONPAY')->and($this->product->fresh()->name)->toBe('Updated name');
    $this->product->refresh();
    expect(phaseTenIssue($this)->status)->toBe(CardIssueStatus::Succeeded);
});

function phaseTenProvider($test, MockProviderMode $mode, string $cardholderMode = 'READY'): void
{
    app()->instance(CardProviderInterface::class, new MockCardProvider($mode, $cardholderMode));
}

function localSimulationReadyCard($test, bool $merchant = false): UserCard
{
    phaseTenReadyUser($test, '200.00000000');
    // Preserve the historical fixture as-is; the local simulator creates independent new identities.
    phaseTenIssue($test);
    config(['card-provider.driver' => 'mock', 'card-provider.mock_mode' => 'SUCCESS', 'card-provider.mock_cardholder_mode' => 'READY']);
    app()->detectEnvironment(fn () => 'local');
    app()->forgetInstance(CardProviderInterface::class);
    Http::preventStrayRequests();
    if ($merchant) {
        $platform = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
        $reference = app(SaveCardProviderReferenceAction::class)->execute(null,
            ['request_id' => (string) Str::uuid(), 'name' => 'test', 'reference_balance' => '0'], $platform);
        expect($reference->runtime_driver)->toBe('LOCAL_MOCK');
        $product = app(CreateCardProductAction::class)->execute([
            'name' => 'Test merchant product', 'card_provider_reference_id' => $reference->id, 'provider_product_ref' => '123456',
            'minimum_initial_load' => '20', 'opening_fee' => '5.00000000', 'minimum_reload' => '20', 'status' => 'ACTIVE',
        ], $platform);
        $config = TenantCardProductConfig::query()->where('tenant_id', $test->tenant->id)->where('card_product_id', $test->product->id)->sole()->replicate();
        $config->card_product_id = $product->id;
        $config->save();
        $test->product = $product;
        $catalog = app(CardProductCatalogQuery::class)->user($test->tenant->id, $test->user->id);
        expect(collect($catalog['products'])->firstWhere('id', $product->id)['readyForSetup'])->toBeTrue();
    }
    $test->holder = app(SubmitProviderCardholderAction::class)->execute($test->tenant->id, $test->user->id, independentCardMaterials($test));
    expect($test->holder->provider_cardholder_id)->toStartWith('MOCK-LOCAL-HOLDER-');
    $issue = phaseTenIssue($test);
    expect($issue->status)->toBe(CardIssueStatus::Succeeded);
    if ($merchant) {
        expect($issue->provider_product_ref)->toBe('MOCK-LOCAL-PRODUCT-'.$test->product->id)
            ->and($reference->fresh()->reference_balance)->toBe('0.00000000');
    }

    return UserCard::query()->where('tenant_id', $test->tenant->id)->where('card_issue_order_id', $issue->id)->firstOrFail();
}

it('archives only confirmed cleared cards after their cancellation return settles', function (): void {
    try {
        $card = localSimulationReadyCard($this, true);
        $request = (string) Str::uuid();
        app(AuditLogger::class)->record($this->tenant->id, 'USER', $this->user->id,
            'CARD_CLEANUP_REQUESTED', 'user_card', $card->id, null, ['balance_before_cancellation' => '20.00000000'], $request);
        $archive = app(ArchiveClearedUserCardAction::class);
        expect(fn () => $archive->execute($this->tenant->id, $this->user->id, $card->id, $request))->toThrow(DomainException::class);
        $manage = app(ManageCardAction::class);
        expect($manage->operate($this->tenant->id, $this->user->id, $card->id, $request, 'CANCEL')->status)->toBe('SUCCEEDED');
        expect(fn () => $archive->execute($this->tenant->id, $this->user->id, $card->id, $request))->toThrow(DomainException::class);
        $tx = app(CardProviderInterface::class)->getTransactionPage($card->provider_card_id, 1, 20)->items[0]->providerTransactionId;
        $manage->settleCancellationReturn($this->tenant->id, $this->user->id, $card->id, $tx);
        $before = [UserCard::query()->count(), CardIssueOrder::query()->count(), LedgerEntry::query()->count(), phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance];
        expect(fn () => $archive->execute($this->tenant->id, (string) Str::uuid(), $card->id, $request))->toThrow(ModelNotFoundException::class);
        $archive->execute($this->tenant->id, $this->user->id, $card->id, $request);
        $archive->execute($this->tenant->id, $this->user->id, $card->id, $request);
        expect($card->fresh()->archived_at)->not->toBeNull()
            ->and([UserCard::query()->count(), CardIssueOrder::query()->count(), LedgerEntry::query()->count(), phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance])->toBe($before)
            ->and(collect(app(UserCardCenterQuery::class)->get($this->tenant->id, $this->user->id)['cards'])->pluck('id')->all())->not->toContain($card->id)
            ->and(DB::table('audit_logs')->where('action', 'USER_CARD_ARCHIVED')->where('resource_id', $card->id)->count())->toBe(1);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
        app()->forgetInstance(CardProviderInterface::class);
    }
});

it('freezes supported cards even when a historical card blocks refund and keeps transaction reads available', function (): void {
    try {
        $card = localSimulationReadyCard($this, true);
        $this->tenant->businessSettings()->update(['security_deposit_refund_wait_days' => 1]);
        $refunds = app(RefundSecurityDepositAction::class);
        $refund = $refunds->request($this->tenant->id, $this->user->id, (string) Str::uuid());
        $refunds->settle($this->tenant->id, $this->user->id, $refund->id);
        expect($refund->fresh()->progress)->toBe('blocked')->and($card->fresh()->provider_status)->toBe('frozen');
        $view = app(UserCardCenterQuery::class)->get($this->tenant->id, $this->user->id);
        expect(collect($view['cards'])->firstWhere('id', $card->id)['management'])->toBe(['transactions']);
        expect(app(UserCardTransactionsQuery::class)->get($this->tenant->id, $this->user->id, $card->id, 1))
            ->toBe(['page' => 1, 'hasMore' => false, 'items' => []]);
        expect(LedgerEntry::query()->where('event_type', 'SECURITY_DEPOSIT_REFUND')->count())->toBe(0);
        $refunds->cancel($this->tenant->id, $this->user->id, $refund->id);
        $refunds->settle($this->tenant->id, $this->user->id, $refund->id);
        expect($refund->fresh()->status)->toBe('CANCELLED')->and($card->fresh()->provider_status)->toBe('normal');
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('runs the local simulator through card management ledger reveal and authenticated notification flows', function (bool $merchant): void {
    try {
        $card = localSimulationReadyCard($this, $merchant);
        $provider = app(CardProviderInterface::class);
        $manage = app(ManageCardAction::class);
        $before = phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance;
        $quote = $manage->quote($this->tenant->id, $this->user->id, $card->id, (string) Str::uuid(), '20.01');
        expect($quote->status)->toBe('QUOTED');
        $loaded = $manage->confirmLoad($this->tenant->id, $this->user->id, $card->id, $quote->id);
        expect($loaded->status)->toBe('SUCCEEDED');
        $manage->confirmLoad($this->tenant->id, $this->user->id, $card->id, $quote->id);
        $returned = $manage->operate($this->tenant->id, $this->user->id, $card->id, (string) Str::uuid(), 'RETURN', '5.01');
        expect($returned->status)->toBe('SUCCEEDED');
        foreach (['FREEZE' => 'frozen', 'UNFREEZE' => 'normal'] as $kind => $status) {
            expect($manage->operate($this->tenant->id, $this->user->id, $card->id, (string) Str::uuid(), $kind)->status)->toBe('SUCCEEDED');
            expect($card->fresh()->provider_status)->toBe($status);
        }
        expect($manage->operate($this->tenant->id, $this->user->id, $card->id, (string) Str::uuid(), 'HOLDER_UPDATE', holderFields: ['email' => 'mock-change@example.test'])->status)->toBe('SUCCEEDED');
        $this->actingAs($this->user, 'tenant_user')->withSession(['_token' => 'local-test-csrf'])->postJson('http://a.localhost/cards/'.$card->id.'/management', [
            'action' => 'reveal', 'current_password' => 'local-password', '_token' => 'local-test-csrf',
        ])->assertStatus(503)->assertJsonMissingPath('pan')->assertJsonMissingPath('cvv'); // The simulator deliberately returns a non-PAN placeholder.
        $tx = $provider->simulatePurchase($card->provider_card_id, 'local-purchase', '3.25');
        $walletBeforeNotification = phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance;
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        config(['card-provider.photonpay.webhook_public_key' => openssl_pkey_get_details($key)['key']]);
        $body = json_encode(['cardId' => $card->provider_card_id, 'transactionId' => $tx, 'cardBalance' => '999', 'tenant_id' => (string) Str::uuid()]);
        openssl_sign($body, $signature, $key, OPENSSL_ALGO_MD5);
        $receive = app(ReceiveCardNotificationAction::class);
        expect(fn () => $receive->execute($body, 'bad-signature', 'issuing', 'auth'))->toThrow(DomainException::class);
        for ($i = 0; $i < 2; $i++) {
            $receive->execute($body, base64_encode($signature), 'issuing', 'auth');
        }
        $event = CardProviderEvent::query()->where('card_id', $card->id)->sole();
        for ($i = 0; $i < 2; $i++) {
            app(ProcessCardNotificationAction::class)->execute($event->tenant_id, $event->id);
        }
        expect($event->fresh()->status)->toBe('PROCESSED')
            ->and($card->fresh()->provider_balance)->toBe('31.75000000')
            ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe($walletBeforeNotification)
            ->and(CardTransaction::query()->where('card_id', $card->id)->count())->toBe(1);
        app(SyncUserCardTransactionsAction::class)->execute($this->tenant->id, $this->user->id, $card->id, 1);
        expect(app(UserCardTransactionsQuery::class)->get($this->tenant->id, $this->user->id, $card->id, 1)['items'])->toHaveCount(3);
        expect(fn () => app(UserCardTransactionsQuery::class)->get($this->otherTenantId ?? (string) Str::uuid(), $this->user->id, $card->id, 1))->toThrow(ModelNotFoundException::class);
        expect($manage->operate($this->tenant->id, $this->user->id, $card->id, (string) Str::uuid(), 'CANCEL')->status)->toBe('SUCCEEDED');
        $returnTx = $provider->getTransactionPage($card->provider_card_id, 1, 20)->items[0]->providerTransactionId;
        $cancelReturn = $manage->settleCancellationReturn($this->tenant->id, $this->user->id, $card->id, $returnTx);
        $manage->settleCancellationReturn($this->tenant->id, $this->user->id, $card->id, $returnTx);
        expect($cancelReturn->status)->toBe('SUCCEEDED')->and($card->fresh()->provider_status)->toBe('cancelled')
            ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe(Money::of($before, 'USDT')->add(Money::of('16.75', 'USDT'))->amount());
        Http::assertNothingSent();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
        app()->forgetInstance(CardProviderInterface::class);
    }
})->with([false, true]);

it('retains or releases local mock recharge holds only after stable provider evidence', function (string $mode): void {
    try {
        $card = localSimulationReadyCard($this);
        $manage = app(ManageCardAction::class);
        $before = phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance;
        $quote = $manage->quote($this->tenant->id, $this->user->id, $card->id, (string) Str::uuid(), '20');
        config(['card-provider.mock_mode' => $mode]);
        $order = $manage->confirmLoad($this->tenant->id, $this->user->id, $card->id, $quote->id);
        expect($order->status)->toBeIn(['UNKNOWN', 'PROCESSING']);
        expect(phaseTenAccount($this, LedgerAccountType::UserCardFundingHold)->balance)->toBe('20.00000000');
        config(['card-provider.mock_mode' => 'SUCCESS']);
        $result = $manage->sync($this->tenant->id, $order->id);
        $failed = $mode === 'DELAYED_FAILURE';
        expect($result->status)->toBe($failed ? 'FAILED' : 'SUCCEEDED')
            ->and(phaseTenAccount($this, LedgerAccountType::UserCardFundingHold)->balance)->toBe('0.00000000')
            ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe($failed ? $before : Money::of($before, 'USDT')->subtract(Money::of('20', 'USDT'))->amount());
        $entries = LedgerEntry::query()->count();
        $manage->sync($this->tenant->id, $order->id);
        expect(LedgerEntry::query()->count())->toBe($entries);
        Http::assertNothingSent();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
        app()->forgetInstance(CardProviderInterface::class);
    }
})->with(['UNKNOWN', 'TIMEOUT', 'DELAYED_SUCCESS', 'DELAYED_FAILURE']);

it('simulates duplicate consumption notifications through the isolated local CLI only', function (): void {
    try {
        $card = localSimulationReadyCard($this);
        $request = (string) Str::uuid();
        $args = ['card' => $card->id, 'amount' => '1.25', 'request' => $request];
        $this->artisan('cards:mock-purchase', $args)->assertSuccessful();
        $this->artisan('cards:mock-purchase', $args)->assertSuccessful();
        $event = CardProviderEvent::query()->where('card_id', $card->id)->sole();
        app(ProcessCardNotificationAction::class)->execute($event->tenant_id, $event->id);
        expect($event->fresh()->status)->toBe('PROCESSED')->and($card->fresh()->provider_balance)->toBe('18.75000000');
        app()->detectEnvironment(fn () => 'production');
        $this->artisan('cards:mock-purchase', $args)->assertFailed();
        expect(CardProviderEvent::query()->where('card_id', $card->id)->count())->toBe(1);
        Http::assertNothingSent();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
        app()->forgetInstance(CardProviderInterface::class);
    }
});

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
        ], AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail());
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
    $holder = phaseTenHolder($test, $holderStatus);

    return ['wallet' => $wallet, 'cardholder' => $holder];
}

function phaseTenHolder($test, ProviderCardholderStatus $holderStatus = ProviderCardholderStatus::Ready): ProviderCardholder
{
    $holder = new ProviderCardholder;
    $holder->forceFill([
        'tenant_id' => $test->tenant->id,
        'user_id' => $test->user->id,
        'provider' => 'PHOTONPAY',
        'provider_cardholder_id' => 'MOCK-HOLDER-'.Str::uuid(),
        'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
        'card_product_id' => $test->product->id, 'submission_version' => 1,
        'materials_encrypted' => app(CardholderMaterials::class)->encrypt(json_encode($test->holderMaterials ?? ['test' => 'independent-card-materials'], JSON_THROW_ON_ERROR)),
        'status' => $holderStatus,
        'provider_status' => strtolower($holderStatus->value),
        'provider_review_status' => strtolower($holderStatus->value),
        'submitted_at' => now(),
        'synced_at' => now(),
    ])->save();

    $test->holder = $holder;

    return $holder;
}

function phaseTenIssue($test, string $amount = '20.00', ?string $requestId = null): CardIssueOrder
{
    return app(CreateCardIssueAction::class)->execute(
        $test->tenant->id,
        $test->user->id,
        $requestId ?? (string) Str::uuid(),
        $test->product->id,
        $amount,
        $test->holder->id,
    );
}

function phaseTenAccount($test, LedgerAccountType $type): LedgerAccount
{
    return LedgerAccount::query()->where('tenant_id', $test->tenant->id)->where('user_id', $test->user->id)
        ->where('account_type', $type->value)->firstOrFail();
}

it('blocks live use of mock materials and demo products before holds or uploads', function (bool $liveProduct): void {
    if ($liveProduct) {
        $this->product->forceFill(['provider_product_ref' => 'OPAQUE-LIVE-PRODUCT'])->save();
    }
    phaseTenReadyUser($this);
    $entryCount = LedgerEntry::query()->count();
    $holderCount = ProviderCardholder::query()->count();
    $fileCount = count(Storage::disk('private')->allFiles());
    $provider = Mockery::mock(CardProviderInterface::class);
    $provider->shouldReceive('available')->andReturn(true);
    $provider->shouldReceive('name')->andReturn('PHOTONPAY');
    $provider->shouldNotReceive('createCardholder');
    $provider->shouldNotReceive('issueCard');
    $provider->shouldNotReceive('getCardholder');
    app()->instance(CardProviderInterface::class, $provider);
    $this->app->detectEnvironment(fn (): string => 'local');
    try {
        expect(fn () => phaseTenIssue($this))->toThrow(DomainException::class, 'test card setup');
        if (! $liveProduct) {
            expect(fn () => app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, independentCardMaterials($this)))
                ->toThrow(DomainException::class, 'test card setup');
        }
        expect(fn () => app(SyncProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, $this->holder->id))
            ->toThrow(DomainException::class, 'test card setup');
        // Even a genuinely configured product cannot authorize an old Mock holder.
        expect(fn () => phaseTenIssue($this))->toThrow(DomainException::class, 'test card setup');
        expect(fn () => app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id,
            array_replace(independentCardMaterials($this), ['request_id' => $this->holder->request_id])))
            ->toThrow(DomainException::class, 'test card setup');
        expect(app(UserCardCenterQuery::class)->get($this->tenant->id, $this->user->id)['cardholder']['state'])->toBe('setup');
        expect(CardIssueOrder::query()->count())->toBe(0)
            ->and(LedgerEntry::query()->count())->toBe($entryCount)
            ->and(ProviderCardholder::query()->count())->toBe($holderCount)
            ->and(count(Storage::disk('private')->allFiles()))->toBe($fileCount);
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
    }
})->with([false, true]);

it('uses each PhotonPay add response identity for exactly its corresponding live issue', function (): void {
    $this->product->forceFill(['provider_product_ref' => 'OPAQUE-LIVE-PRODUCT'])->save();
    phaseTenReadyUser($this);
    $historicHolderId = $this->holder->id;
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privateKey);
    config()->set('card-provider.driver', 'photonpay');
    config()->set('card-provider.photonpay', [
        'base_url' => 'https://x-api.photonpay.com', 'app_id' => 'offline-app', 'app_secret' => 'offline-secret',
        'private_key' => $privateKey, 'account_id_usd' => 'FA-OFFLINE', 'member_id' => null, 'matrix_account' => null, 'timeout_seconds' => 10,
    ]);
    app()->forgetInstance(CardProviderInterface::class);
    $calls = [];
    $additions = 0;
    $testTransactionLevel = DB::transactionLevel();
    Http::preventStrayRequests();
    Http::fake(function ($request) use (&$calls, &$additions, $testTransactionLevel) {
        // RefreshDatabase owns an outer test transaction; no business transaction may remain open.
        expect(DB::transactionLevel())->toBe($testTransactionLevel);
        expect(parse_url($request->url(), PHP_URL_HOST))->toBe('x-api.photonpay.com');
        $endpoint = basename(parse_url($request->url(), PHP_URL_PATH));
        $payload = $request->data();
        $data = match ($endpoint) {
            'issuing_cardholder_identity_certificate' => 'FILE-OFFLINE',
            'addCardholder' => ['cardholderId' => 'CH-RETURNED-'.(++$additions)],
            'getCardBin' => [['cardBin' => 'OPAQUE-LIVE-PRODUCT', 'cardCurrency' => 'USD', 'cardType' => 'recharge', 'cardFormFactor' => 'virtual_card']],
            'openCard' => ['requestId' => $payload['requestId'], 'status' => 'succeed', 'cardDetail' => [
                'cardId' => 'XR-RETURNED-'.$additions, 'maskCardNo' => '**** 4321', 'cardCurrency' => 'USD',
                'cardType' => 'recharge', 'cardFormFactor' => 'virtual_card', 'cardBalance' => '20.00',
            ]],
            default => throw new RuntimeException('Unexpected offline Provider request.'),
        };
        if (in_array($endpoint, ['addCardholder', 'openCard'], true)) {
            $calls[] = [$endpoint, $endpoint === 'addCardholder' ? $data['cardholderId'] : $payload['cardholderId']];
        }

        return Http::response(['code' => '0000', 'data' => $data]);
    });
    $this->app->detectEnvironment(fn (): string => 'local');
    try {
        foreach (['Alice', 'Bob'] as $index => $person) {
            $holder = app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, independentCardMaterials($this, $person));
            expect($holder->status)->toBe(ProviderCardholderStatus::Ready)
                ->and($holder->provider_cardholder_id)->toBe('CH-RETURNED-'.($index + 1));
            $this->holder = $holder;
            expect(phaseTenIssue($this)->status)->toBe(CardIssueStatus::Succeeded);
        }
        expect($calls)->toBe([
            ['addCardholder', 'CH-RETURNED-1'], ['openCard', 'CH-RETURNED-1'],
            ['addCardholder', 'CH-RETURNED-2'], ['openCard', 'CH-RETURNED-2'],
        ])->and(ProviderCardholder::query()->findOrFail($historicHolderId)->provider_cardholder_id)->toStartWith('MOCK-')
            ->and(UserCard::query()->count())->toBe(2);
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
    }
});

it('keeps null identity unknown applications blocking new live submissions', function (): void {
    $this->product->forceFill(['provider_product_ref' => 'OPAQUE-LIVE-PRODUCT'])->save();
    phaseTenReadyUser($this);
    phaseTenProvider($this, MockProviderMode::Success, 'UNKNOWN');
    // Finish preparing a genuine uncertain addition without deleting the historical Mock row.
    $this->app->detectEnvironment(fn (): string => 'local');
    try {
        $holder = app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, independentCardMaterials($this));
        expect($holder->provider_cardholder_id)->toBeNull()
            ->and($holder->status)->toBe(ProviderCardholderStatus::Unknown)
            ->and(app(UserCardCenterQuery::class)->get($this->tenant->id, $this->user->id)['cardholder']['state'])->toBe('unknown');
        expect(fn () => app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, independentCardMaterials($this, 'Bob')))
            ->toThrow(DomainException::class, 'Complete the current card application');
        expect(CardIssueOrder::query()->count())->toBe(0);
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
    }
});

it('does not query or release unresolved mock issue holds after a live switch', function (): void {
    phaseTenReadyUser($this);
    phaseTenProvider($this, MockProviderMode::Unknown);
    $order = phaseTenIssue($this);
    $entryCount = LedgerEntry::query()->count();
    $balance = phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance;
    $provider = Mockery::mock(CardProviderInterface::class);
    $provider->shouldNotReceive('queryOperation');
    $provider->shouldNotReceive('issueCard');
    app()->instance(CardProviderInterface::class, $provider);
    $this->app->detectEnvironment(fn (): string => 'local');
    try {
        expect(fn () => app(SyncCardIssueAction::class)->execute($this->tenant->id, $order->id, $this->user->id))
            ->toThrow(DomainException::class, 'test card setup');
        expect(phaseTenIssue($this, requestId: $order->request_id)->id)->toBe($order->id)
            ->and($order->fresh()->status)->toBe(CardIssueStatus::Unknown)
            ->and(LedgerEntry::query()->count())->toBe($entryCount)
            ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe($balance)
            ->and(UserCard::query()->count())->toBe(0);
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
    }
});

it('requires approved KYC and validates all card setup fields before creating a Provider Cardholder', function (): void {
    expect(fn () => app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, []))
        ->toThrow(DomainException::class, 'Approved identity verification');

    $this->actingAs($this->user, 'tenant_user')->post('http://a.localhost/cards/cardholder', [
        'legal_first_name' => '', 'tenant_id' => $this->tenant->id, 'identity_number' => 'must-not-be-accepted',
    ])->assertSessionHasErrors(['legal_first_name', 'legal_last_name', 'date_of_birth', 'tenant_id', 'front', 'back', 'request_id']);
    expect(ProviderCardholder::query()->count())->toBe(0);
});

it('submits independently uploaded cardholder materials without changing account identity', function (): void {
    phaseTenReadyUser($this);
    ProviderCardholder::query()->delete();
    $data = [
        'request_id' => (string) Str::uuid(), 'card_product_id' => $this->product->id,
        'email' => 'different-person@example.test', 'document_type' => 'id_card', 'document_country' => 'MY', 'identity_number' => 'DIFFERENT-HOLDER-001',
        'front' => kycTestImage(), 'back' => kycTestImage(),
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
        ->and(app(CardholderMaterials::class)->decrypt($holder->materials_encrypted))->toContain('DIFFERENT-HOLDER-001')
        ->and($holder->toJson())->not->toContain('DIFFERENT-HOLDER-001', 'materials_encrypted', 'request_hash')
        ->and(AuditLog::query()->where('action', 'PHOTONPAY_CARDHOLDER_SUBMITTED')->count())->toBe(1);
});

it('does not issue for pending or rejected Cardholders and issues only when ready', function (ProviderCardholderStatus $status): void {
    phaseTenReadyUser($this, holderStatus: $status);
    if ($status === ProviderCardholderStatus::Ready) {
        expect(phaseTenIssue($this)->status)->toBe(CardIssueStatus::Succeeded);
    } else {
        expect(fn () => phaseTenIssue($this))->toThrow(DomainException::class, 'The cardholder must be added successfully');
        expect(CardIssueOrder::query()->count())->toBe(0);
    }
})->with([ProviderCardholderStatus::Pending, ProviderCardholderStatus::Rejected, ProviderCardholderStatus::Ready]);

it('does not blindly recreate an unknown Cardholder and keeps linkage tenant scoped', function (): void {
    phaseTenProvider($this, MockProviderMode::Success, 'TIMEOUT');
    phaseTenReadyUser($this);
    ProviderCardholder::query()->delete();
    $data = [
        'request_id' => (string) Str::uuid(), 'card_product_id' => $this->product->id,
        'email' => 'different-person@example.test', 'document_type' => 'id_card', 'document_country' => 'MY', 'identity_number' => 'DIFFERENT-HOLDER-002',
        'front' => kycTestImage(), 'back' => kycTestImage(),
        'legal_first_name' => 'Demo', 'legal_last_name' => 'User', 'date_of_birth' => '1990-01-02',
        'nationality_country_code' => 'MY', 'residential_address' => '1 Demo Street', 'residential_city' => 'Kuala Lumpur',
        'residential_state' => 'Kuala Lumpur', 'residential_country_code' => 'MY', 'residential_postal_code' => '50000',
    ];
    $first = app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, $data);
    expect($first->status)->toBe(ProviderCardholderStatus::Unknown)
        ->and(app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, $data)->id)->toBe($first->id)
        ->and(fn () => app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, array_replace($data, ['request_id' => (string) Str::uuid()])))->toThrow(DomainException::class, 'current card application')
        ->and(ProviderCardholder::query()->count())->toBe(1);

    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    expect(fn () => app(SyncProviderCardholderAction::class)->execute($tenantB->id, $this->user->id, $first->id))->toThrow(ModelNotFoundException::class);
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
    $this->product->forceFill(['opening_fee' => '0.00000000'])->save();
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

    phaseTenHolder($this);
    expect(fn () => phaseTenIssue($this, requestId: (string) Str::uuid()))->toThrow(DomainException::class, 'maximum')
        ->and(fn () => app(SyncCardIssueAction::class)->execute(Tenant::query()->where('slug', 'tenant-b')->value('id'), $order->id, $this->user->id))->toThrow(ModelNotFoundException::class);
});

it('reserves max-card capacity while an issue outcome remains unresolved', function (): void {
    phaseTenProvider($this, MockProviderMode::Unknown);
    phaseTenReadyUser($this, '100');
    TenantCardProductConfig::query()->where('tenant_id', $this->tenant->id)
        ->where('card_product_id', $this->product->id)
        ->update(['max_cards_per_user' => 1]);

    expect(phaseTenIssue($this)->status)->toBe(CardIssueStatus::Unknown);
    phaseTenHolder($this);
    expect(fn () => phaseTenIssue($this, requestId: (string) Str::uuid()))
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
    $this->actingAs($platform, 'platform_admin')->get('http://admin.localhost/platform/cards')->assertOk()->assertInertia(fn (Assert $page) => $page->component('platform/Cards')->has('cards.data', 1)->has('orders.data', 1));
    $this->get('http://admin.localhost/platform/cards?company='.$this->tenant->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('cards.data', 1)->where('cards.data.0.tenantId', $this->tenant->id)->where('cards.data.0.companyName', $this->tenant->name));
    $otherCompany = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $this->get('http://admin.localhost/platform/cards?company='.$otherCompany->id)->assertOk()->assertInertia(fn (Assert $page) => $page->has('cards.data', 0)->has('orders.data', 0));
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

function independentCardMaterials($test, string $person = 'Alice'): array
{
    return [
        'request_id' => (string) Str::uuid(), 'card_product_id' => $test->product->id,
        'legal_first_name' => $person, 'legal_last_name' => 'Holder', 'date_of_birth' => '1992-03-04',
        'email' => 'holder-contact@example.test', 'nationality_country_code' => 'MY',
        'residential_address' => '9 Holder Road', 'residential_city' => 'Kuala Lumpur',
        'residential_state' => 'Kuala Lumpur', 'residential_country_code' => 'MY', 'residential_postal_code' => '50000',
        'document_type' => 'id_card', 'identity_number' => 'PERSON-'.$person,
        'front' => kycTestImage(), 'back' => kycTestImage(),
    ];
}

it('derives the issuing country from this cardholders nationality without a separate input', function (string $nationality): void {
    phaseTenReadyUser($this);
    ProviderCardholder::query()->delete();
    $provider = Mockery::mock(CardProviderInterface::class);
    $provider->shouldReceive('available')->andReturn(true);
    $provider->shouldReceive('name')->andReturn('PHOTONPAY');
    $provider->shouldReceive('createCardholder')->once()->andReturnUsing(function ($request) use ($nationality) {
        expect($request->nationalityCountryCode)->toBe($nationality)
            ->and($request->identityDocument->countryCode)->toBe($nationality)
            ->and($request->residentialCountryCode)->toBe('MY')
            ->and($request->identityDocument->identityNumber)->toBe('PERSON-Alice');

        return (new MockCardProvider)->createCardholder($request);
    });
    $provider->shouldNotReceive('issueCard');
    app()->instance(CardProviderInterface::class, $provider);
    $data = array_replace(independentCardMaterials($this), ['nationality_country_code' => $nationality]);
    expect($data)->not->toHaveKey('document_country');
    $this->actingAs($this->user, 'tenant_user')->from('http://a.localhost/cards')
        ->post('http://a.localhost/cards/cardholder', $data)->assertSessionHasNoErrors()->assertRedirect('http://a.localhost/cards');
    $holder = ProviderCardholder::query()->firstOrFail();
    $envelope = json_decode(app(CardholderMaterials::class)->decrypt($holder->materials_encrypted), true, flags: JSON_THROW_ON_ERROR);
    expect($envelope['fields']['document_country'])->toBe($nationality)
        ->and($envelope['fields']['nationality_country_code'])->toBe($nationality);
    // Even a direct Application caller cannot override the derived value or bypass replay protection.
    $replay = app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, array_replace($data, ['document_country' => 'US']));
    expect($replay->id)->toBe($holder->id)
        ->and($replay->request_hash)->toBe($holder->request_hash)
        ->and(ProviderCardholder::query()->count())->toBe(1)
        ->and(CardIssueOrder::query()->count())->toBe(0)
        ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe('100.00000000');
})->with(['MY', 'CN']);

it('rejects a client supplied issuing country before any cardholder submission', function (): void {
    phaseTenReadyUser($this);
    $beforeHolders = ProviderCardholder::query()->count();
    $provider = Mockery::mock(CardProviderInterface::class);
    $provider->shouldNotReceive('createCardholder');
    $provider->shouldNotReceive('issueCard');
    app()->instance(CardProviderInterface::class, $provider);
    $data = array_replace(independentCardMaterials($this), ['document_country' => 'US']);
    $this->actingAs($this->user, 'tenant_user')->post('http://a.localhost/cards/cardholder', $data)
        ->assertSessionHasErrors(['document_country']);
    expect(ProviderCardholder::query()->count())->toBe($beforeHolders)
        ->and(CardIssueOrder::query()->count())->toBe(0)
        ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe('100.00000000');
});

it('submits cardholder materials without an identity number and never falls back to account identity', function (string $mode): void {
    phaseTenReadyUser($this);
    ProviderCardholder::query()->delete();
    $identityBefore = DB::table('identity_records')->where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->first();
    $provider = Mockery::mock(CardProviderInterface::class);
    $provider->shouldReceive('available')->andReturn(true);
    $provider->shouldReceive('name')->andReturn('PHOTONPAY');
    $provider->shouldReceive('createCardholder')->once()->andReturnUsing(function ($request) {
        expect($request->identityDocument->identityNumber)->toBeNull()
            ->and($request->identityDocument->countryCode)->toBe($request->nationalityCountryCode)
            ->and($request->identityDocument->frontContents)->not->toBeEmpty();

        return (new MockCardProvider)->createCardholder($request);
    });
    $provider->shouldNotReceive('issueCard');
    app()->instance(CardProviderInterface::class, $provider);
    $data = independentCardMaterials($this);
    unset($data['identity_number']);
    if ($mode !== 'omitted') {
        $data['identity_number'] = $mode === 'null' ? null : '';
    }
    $this->actingAs($this->user, 'tenant_user')->from('http://a.localhost/cards')
        ->post('http://a.localhost/cards/cardholder', $data)->assertSessionHasNoErrors()->assertRedirect('http://a.localhost/cards');
    $holder = ProviderCardholder::query()->firstOrFail();
    $envelope = json_decode(app(CardholderMaterials::class)->decrypt($holder->materials_encrypted), true, flags: JSON_THROW_ON_ERROR);
    expect($envelope['fields']['identity_number'])->toBeNull()
        ->and($envelope['fields']['document_country'])->toBe('MY')
        ->and(DB::table('identity_records')->where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->first())->toEqual($identityBefore)
        ->and(CardIssueOrder::query()->count())->toBe(0)
        ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe('100.00000000');
    foreach ([null, ''] as $empty) {
        $replayed = app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, array_replace($data, ['identity_number' => $empty]));
        expect($replayed->id)->toBe($holder->id)->and($replayed->request_hash)->toBe($holder->request_hash);
    }
})->with(['omitted', 'null', 'blank']);

it('adds one new holder per HTTP card application and issues with its exact returned identity without a review query', function (): void {
    phaseTenReadyUser($this);
    ProviderCardholder::query()->delete();
    $mock = new MockCardProvider;
    $calls = [];
    $provider = Mockery::mock(CardProviderInterface::class);
    $provider->shouldReceive('available')->andReturn(true);
    $provider->shouldReceive('name')->andReturn('PHOTONPAY');
    $provider->shouldNotReceive('getCardholder');
    $provider->shouldNotReceive('updateCardholder');
    $provider->shouldReceive('createCardholder')->twice()->andReturnUsing(function ($request) use ($mock, &$calls) {
        $result = $mock->createCardholder($request);
        $calls[] = ['add', $result->providerCardholderId];

        return $result;
    });
    $provider->shouldReceive('issueCard')->twice()->andReturnUsing(function ($request) use ($mock, &$calls) {
        $calls[] = ['issue', $request->holderReference];

        return $mock->issueCard($request);
    });
    app()->instance(CardProviderInterface::class, $provider);
    $this->actingAs($this->user, 'tenant_user')->from('http://a.localhost/cards');
    $identities = [];
    foreach (['Alice', 'Bruno'] as $person) {
        $data = independentCardMaterials($this, $person);
        unset($data['identity_number']);
        $before = phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance;
        $this->post('http://a.localhost/cards/cardholder', $data)->assertSessionHasNoErrors()->assertSessionMissing('success');
        $holder = ProviderCardholder::query()->where('request_id', $data['request_id'])->firstOrFail();
        $identities[] = $holder->provider_cardholder_id;
        expect(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe($before);
        $this->get('http://a.localhost/cards')->assertInertia(fn (Assert $page) => $page->component('user/Cards')
            ->where('cardholder.state', 'ready')->where('cardholder.id', $holder->id)->where('cardholder.productId', $this->product->id)
            ->missing('cardholder.provider_cardholder_id'));
        $this->post('http://a.localhost/cards/cardholder', $data)->assertSessionHasNoErrors();
        $this->post('http://a.localhost/cards/issues', [
            'request_id' => (string) Str::uuid(), 'card_product_id' => $this->product->id,
            'initial_load_amount' => '20.00', 'cardholder_application_id' => $holder->id,
        ])->assertSessionHasNoErrors();
        // Consume the issue success flash; an addition must not produce a review banner.
        $this->get('http://a.localhost/cards')->assertOk();
    }
    expect($identities[0])->not->toBe($identities[1])
        ->and($calls)->toBe([['add', $identities[0]], ['issue', $identities[0]], ['add', $identities[1]], ['issue', $identities[1]]])
        ->and(UserCard::query()->count())->toBe(2)
        ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe('50.00000000');
});

it('returns addition errors inside the application without a review success flash or funds movement', function (string $mode, string $message): void {
    phaseTenReadyUser($this);
    ProviderCardholder::query()->delete();
    phaseTenProvider($this, MockProviderMode::Success, $mode);
    $data = independentCardMaterials($this);
    $this->actingAs($this->user, 'tenant_user')->from('http://a.localhost/cards')
        ->post('http://a.localhost/cards/cardholder', $data)
        ->assertRedirect('http://a.localhost/cards')->assertSessionHasErrors(['form' => $message])->assertSessionMissing('success');
    expect(CardIssueOrder::query()->count())->toBe(0)
        ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe('100.00000000');
    if ($mode === 'REJECTED') {
        phaseTenProvider($this, MockProviderMode::Success);
        $this->post('http://a.localhost/cards/cardholder', array_replace($data, ['request_id' => (string) Str::uuid()]))->assertSessionHasNoErrors();
        expect(ProviderCardholder::query()->count())->toBe(2);
    } else {
        expect(fn () => app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, array_replace($data, ['request_id' => (string) Str::uuid()])))->toThrow(DomainException::class, 'current card application');
    }
})->with([
    ['REJECTED', 'The cardholder could not be added. Check the details and try again.'],
    ['TIMEOUT', 'The cardholder addition could not be confirmed. Do not submit it again.'],
]);

it('keeps a ready claim without the returned identity unconfirmed and unable to issue', function (): void {
    phaseTenReadyUser($this);
    ProviderCardholder::query()->delete();
    $provider = Mockery::mock(CardProviderInterface::class);
    $provider->shouldReceive('available')->andReturn(true);
    $provider->shouldReceive('name')->andReturn('PHOTONPAY');
    $provider->shouldReceive('createCardholder')->once()->andReturn(new ProviderCardholderDTO(null, ProviderCardholderReviewStatus::Ready));
    $provider->shouldNotReceive('issueCard');
    app()->instance(CardProviderInterface::class, $provider);
    $this->actingAs($this->user, 'tenant_user')->post('http://a.localhost/cards/cardholder', independentCardMaterials($this))
        ->assertSessionHasErrors(['form' => 'The cardholder addition could not be confirmed. Do not submit it again.'])->assertSessionMissing('success');
    $this->holder = ProviderCardholder::query()->firstOrFail();
    expect($this->holder->status)->toBe(ProviderCardholderStatus::Unknown)
        ->and(fn () => phaseTenIssue($this))->toThrow(DomainException::class, 'must be added successfully')
        ->and(CardIssueOrder::query()->count())->toBe(0);
});

it('rejects malformed holder contacts at the HTTP boundary with field errors and no provider work', function (): void {
    phaseTenReadyUser($this);
    $beforeHolders = ProviderCardholder::query()->count();
    $provider = Mockery::mock(CardProviderInterface::class);
    $provider->shouldNotReceive('createCardholder');
    $provider->shouldNotReceive('issueCard');
    app()->instance(CardProviderInterface::class, $provider);
    $data = array_replace(independentCardMaterials($this), ['email' => '1111', 'mobile' => '1111', 'mobile_country_code' => 'CN']);
    $this->actingAs($this->user, 'tenant_user')->from('http://a.localhost/cards')->post('http://a.localhost/cards/cardholder', $data)
        ->assertSessionHasErrors(['email' => 'Enter a valid email address.', 'mobile' => 'Select a calling code and enter a valid mobile number.']);
    expect(ProviderCardholder::query()->count())->toBe($beforeHolders)
        ->and(CardIssueOrder::query()->count())->toBe(0)
        ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe('100.00000000')
        ->and(session()->getOldInput())->not->toHaveKeys(['email', 'mobile', 'front', 'back', 'identity_number']);
});

it('creates two cards for different people in add-holder then issue order without reading or mutating account materials', function (): void {
    phaseTenReadyUser($this);
    ProviderCardholder::query()->delete();
    $identityBefore = DB::table('identity_records')->where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->first();
    $profileBefore = $this->user->profile->getAttributes();
    // The account's original documents are deliberately unavailable; independently uploaded materials still work.
    Storage::disk('private')->deleteDirectory('kyc');
    $calls = [];
    $mockProvider = new MockCardProvider;
    $provider = Mockery::mock(CardProviderInterface::class);
    $provider->shouldReceive('name')->andReturn('PHOTONPAY');
    $provider->shouldReceive('available')->andReturn(true);
    $outerLevel = DB::transactionLevel();
    $provider->shouldReceive('createCardholder')->twice()->andReturnUsing(function ($request) use (&$calls, $mockProvider, $outerLevel) {
        expect(DB::transactionLevel())->toBe($outerLevel);
        $calls[] = ['add', $request->firstName, $request->identityDocument->identityNumber];

        return $mockProvider->createCardholder($request);
    });
    $provider->shouldReceive('issueCard')->twice()->andReturnUsing(function ($request) use (&$calls, $mockProvider, $outerLevel) {
        expect(DB::transactionLevel())->toBe($outerLevel);
        $calls[] = ['issue', $request->holderReference];

        return $mockProvider->issueCard($request);
    });
    app()->instance(CardProviderInterface::class, $provider);
    $holders = [];
    foreach (['Alice', 'Bruno'] as $name) {
        $data = independentCardMaterials($this, $name);
        unset($data['identity_number']);
        $holder = app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, $data);
        $holders[] = $holder;
        $this->holder = $holder;
        $order = phaseTenIssue($this);
        expect($order->provider_cardholder_id)->toBe($holder->id)
            ->and($order->cardholder_request_id)->toBe($holder->request_id)
            ->and($order->status)->toBe(CardIssueStatus::Succeeded);
    }
    expect($calls)->toBe([
        ['add', 'Alice', null], ['issue', $holders[0]->provider_cardholder_id],
        ['add', 'Bruno', null], ['issue', $holders[1]->provider_cardholder_id],
    ])->and($holders[0]->provider_cardholder_id)->not->toBe($holders[1]->provider_cardholder_id)
        ->and($this->user->profile->fresh()->getAttributes())->toBe($profileBefore)
        ->and(DB::table('identity_records')->where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->first())->toEqual($identityBefore)
        ->and(UserCard::query()->count())->toBe(2)
        ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe('50.00000000');
});

it('encrypts per-card data and uploaded files and excludes it from props serialization audit and session', function (): void {
    phaseTenReadyUser($this);
    ProviderCardholder::query()->delete();
    $data = independentCardMaterials($this);
    $this->actingAs($this->user, 'tenant_user')->post('http://a.localhost/cards/cardholder', $data)->assertSessionHasNoErrors();
    $holder = ProviderCardholder::query()->firstOrFail();
    $cipher = app(CardholderMaterials::class);
    $envelope = json_decode($cipher->decrypt($holder->materials_encrypted), true, flags: JSON_THROW_ON_ERROR);
    expect($holder->materials_encrypted)->not->toContain('PERSON-Alice', 'holder-contact', 'Holder Road');
    foreach ($envelope['documents'] as $path) {
        $encrypted = Storage::disk('private')->get($path);
        expect($encrypted)->not->toBe($data['front']->getContent())
            ->and($cipher->decrypt($encrypted))->toBe($data['front']->getContent());
        Storage::disk('public')->assertMissing($path);
    }
    $query = app(UserCardCenterQuery::class)->get($this->tenant->id, $this->user->id);
    $serialized = json_encode($query).' '.$holder->toJson().' '.AuditLog::query()->get()->toJson().' '.json_encode(session()->all());
    expect($serialized)->not->toContain('PERSON-Alice', 'holder-contact', 'materials_encrypted', 'front_object_key', 'card-materials/')
        ->and($query)->not->toHaveKey('profile')
        ->and($query['cardholder']['id'])->toBe($holder->id);
    $this->post('http://a.localhost/cards/cardholder', array_replace($data, ['legal_first_name' => '']))->assertSessionHasErrors('legal_first_name');
    expect(json_encode(session()->get('_old_input')))->not->toContain('PERSON-Alice', 'holder-contact', 'Holder Road');
});

it('replays one unchanged material request without another provider creation', function (): void {
    phaseTenReadyUser($this);
    ProviderCardholder::query()->delete();
    $data = independentCardMaterials($this);
    $holder = app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, $data);
    $files = Storage::disk('private')->allFiles('card-materials');
    $replayed = app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, $data);
    expect($replayed->id)->toBe($holder->id)
        ->and($replayed->provider_cardholder_id)->toBe($holder->provider_cardholder_id)
        ->and(ProviderCardholder::query()->count())->toBe(1)
        ->and(Storage::disk('private')->allFiles('card-materials'))->toBe($files)
        ->and(CardIssueOrder::query()->count())->toBe(0)
        ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe('100.00000000');
});

it('edits an unissued ready holder with the same identity and scopes private prefill then seals it after issue', function (): void {
    phaseTenReadyUser($this);
    ProviderCardholder::query()->delete();
    $data = independentCardMaterials($this);
    $action = app(SubmitProviderCardholderAction::class);
    $holder = $action->execute($this->tenant->id, $this->user->id, $data);
    $url = 'http://a.localhost/cards/cardholder/'.$holder->id.'/details';
    $this->actingAs($this->user, 'tenant_user')->postJson($url)->assertOk()
        ->assertJsonPath('fields.legal_first_name', $data['legal_first_name'])
        ->assertJsonPath('fields.email', $data['email'])->assertJsonMissingPath('fields.identity_number')
        ->assertJsonMissingPath('fields.documents')->assertJsonMissingPath('fields.request_id');
    $other = User::query()->where('tenant_id', '!=', $this->tenant->id)->firstOrFail();
    $this->actingAs($other, 'tenant_user')->postJson('http://b.localhost/cards/cardholder/'.$holder->id.'/details')->assertNotFound();
    $before = LedgerEntry::query()->count();
    $this->travel(1)->seconds();
    $changed = array_replace($data, ['residential_address' => 'Updated Holder Road']);
    $edited = $action->execute($this->tenant->id, $this->user->id, $changed);
    expect($edited->id)->toBe($holder->id)->and($edited->provider_cardholder_id)->toBe($holder->provider_cardholder_id)
        ->and($edited->submission_version)->toBe(2)->and($edited->status)->toBe(ProviderCardholderStatus::Ready)
        ->and($action->execute($this->tenant->id, $this->user->id, $changed)->submission_version)->toBe(2)
        ->and(ProviderCardholder::query()->count())->toBe(1)->and(LedgerEntry::query()->count())->toBe($before);
    $this->actingAs($this->user, 'tenant_user')->postJson($url)->assertOk()->assertJsonPath('fields.residential_address', 'Updated Holder Road');
    $this->holder = $edited;
    phaseTenIssue($this);
    $this->postJson($url)->assertStatus(409);
    expect(fn () => $action->execute($this->tenant->id, $this->user->id, $data))->toThrow(DomainException::class, 'different details');
    expect(fn () => DB::transaction(fn () => $edited->forceFill(['status' => 'SUBMITTING', 'submission_version' => 3, 'request_hash' => str_repeat('b', 64)])->save()))->toThrow(QueryException::class);
});

it('keeps an uncertain ready-holder revision locked despite an old ready provider status', function (): void {
    phaseTenReadyUser($this);
    ProviderCardholder::query()->delete();
    $data = independentCardMaterials($this);
    $holder = app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, $data);
    $provider = Mockery::mock(CardProviderInterface::class);
    $provider->shouldReceive('available')->andReturn(true);
    $provider->shouldReceive('name')->andReturn('PHOTONPAY');
    $provider->shouldReceive('updateCardholder')->once()->andThrow(new ProviderUnknownResultException('Unconfirmed'));
    $provider->shouldReceive('getCardholder')->once()->andReturn(new ProviderCardholderDTO($holder->provider_cardholder_id, ProviderCardholderReviewStatus::Ready));
    app()->instance(CardProviderInterface::class, $provider);
    $action = app(SubmitProviderCardholderAction::class);
    $changed = array_replace($data, ['residential_address' => 'Changed street']);
    $this->holder = $action->execute($this->tenant->id, $this->user->id, $changed);
    expect($this->holder->status)->toBe(ProviderCardholderStatus::Unknown);
    expect(app(SyncProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, $holder->id)->status)->toBe(ProviderCardholderStatus::Unknown);
    expect(fn () => $action->execute($this->tenant->id, $this->user->id, $data))->toThrow(DomainException::class)
        ->and(fn () => $action->execute($this->tenant->id, $this->user->id, array_replace($changed, ['request_id' => (string) Str::uuid()])))->toThrow(DomainException::class)
        ->and(fn () => phaseTenIssue($this))->toThrow(DomainException::class)
        ->and(CardIssueOrder::query()->count())->toBe(0)
        ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe('100.00000000');
    expect(fn () => DB::transaction(fn () => $this->holder->forceFill(['status' => 'SUBMITTING', 'submission_version' => 3, 'request_hash' => str_repeat('c', 64)])->save()))->toThrow(QueryException::class);
});

it('permits a fresh application after definitive issue failure but never edits or reuses its old holder', function (): void {
    phaseTenReadyUser($this);
    ProviderCardholder::query()->delete();
    $data = independentCardMaterials($this);
    $this->holder = app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, $data);
    phaseTenProvider($this, MockProviderMode::Failed);
    expect(phaseTenIssue($this)->status)->toBe(CardIssueStatus::Failed);
    phaseTenProvider($this, MockProviderMode::Success);
    $fresh = app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, array_replace($data, ['request_id' => (string) Str::uuid()]));
    expect($fresh->id)->not->toBe($this->holder->id)->and($fresh->status)->toBe(ProviderCardholderStatus::Ready);
    expect(fn () => app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, array_replace($data, ['residential_address' => 'Changed'])))->toThrow(DomainException::class);
});

it('keeps funds untouched until the specific application is ready', function (string $mode): void {
    phaseTenReadyUser($this);
    ProviderCardholder::query()->delete();
    phaseTenProvider($this, MockProviderMode::Success, $mode);
    $this->holder = app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, independentCardMaterials($this));
    expect(fn () => phaseTenIssue($this))->toThrow(DomainException::class, 'must be added successfully')
        ->and(CardIssueOrder::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->whereIn('event_type', ['CARD_ISSUE_FEE_HOLD', 'CARD_INITIAL_LOAD_HOLD'])->count())->toBe(0)
        ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe('100.00000000');
})->with(['PENDING', 'TIMEOUT', 'ACTION_REQUIRED', 'REJECTED']);

it('allows action-required correction only for the same application and blocks stale review responses', function (): void {
    phaseTenReadyUser($this);
    ProviderCardholder::query()->delete();
    phaseTenProvider($this, MockProviderMode::Success, 'ACTION_REQUIRED');
    $data = independentCardMaterials($this);
    $holder = app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, $data);
    $oldId = $holder->provider_cardholder_id;
    $provider = Mockery::mock(CardProviderInterface::class);
    $provider->shouldReceive('available')->andReturn(true);
    $provider->shouldReceive('name')->andReturn('PHOTONPAY');
    $provider->shouldReceive('updateCardholder')->once()->andReturn(new ProviderCardholderDTO($oldId, ProviderCardholderReviewStatus::Pending, 'pending', 'pending'));
    $provider->shouldReceive('getCardholder')->once()->andReturnUsing(function () use ($data, $oldId) {
        app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, array_replace($data, ['residential_address' => 'Updated Address']));

        return new ProviderCardholderDTO($oldId, ProviderCardholderReviewStatus::Ready, 'normal', 'approved');
    });
    app()->instance(CardProviderInterface::class, $provider);
    $result = app(SyncProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, $holder->id);
    expect($result->submission_version)->toBe(2)->and($result->status)->toBe(ProviderCardholderStatus::Pending)
        ->and($result->provider_cardholder_id)->toBe($oldId)->and(CardIssueOrder::query()->count())->toBe(0);
});

it('rejects reusing materials for a second order in both application and PostgreSQL', function (): void {
    phaseTenReadyUser($this);
    $first = phaseTenIssue($this);
    expect(fn () => phaseTenIssue($this))->toThrow(DomainException::class, 'each card');
    $attributes = array_replace($first->getAttributes(), ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'provider_request_id' => (string) Str::uuid()]);
    expect(fn () => DB::transaction(fn () => DB::table('card_issue_orders')->insert($attributes)))->toThrow(QueryException::class)
        ->and(CardIssueOrder::query()->count())->toBe(1)
        ->and(UserCard::query()->count())->toBe(1)
        ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe('75.00000000');
});

it('does not allow another tenant or user application to authorize the current card', function (): void {
    phaseTenReadyUser($this);
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $other = (object) ['tenant' => $tenantB, 'user' => User::query()->where('tenant_id', $tenantB->id)->firstOrFail(), 'product' => $this->product];
    $foreign = phaseTenHolder($other);
    expect(fn () => app(CreateCardIssueAction::class)->execute($this->tenant->id, $this->user->id, (string) Str::uuid(), $this->product->id, '20', $foreign->id))->toThrow(DomainException::class, 'must be added successfully')
        ->and(fn () => app(SyncProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, $foreign->id))->toThrow(ModelNotFoundException::class)
        ->and(CardIssueOrder::query()->count())->toBe(0);
});

it('keeps legacy account-level holders readable but never uses them for a new issue', function (): void {
    phaseTenReadyUser($this);
    $legacy = new ProviderCardholder;
    $legacy->forceFill(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'provider' => 'PHOTONPAY', 'provider_cardholder_id' => 'MOCK-LEGACY', 'status' => 'READY'])->save();
    expect(fn () => app(CreateCardIssueAction::class)->execute($this->tenant->id, $this->user->id, (string) Str::uuid(), $this->product->id, '20', $legacy->id))->toThrow(DomainException::class, 'must be added successfully')
        ->and($legacy->fresh()->status)->toBe(ProviderCardholderStatus::Ready)
        ->and($legacy->fresh()->request_id)->toBeNull();
});

it('sends independently selected billing geography and phone through the holder contract', function (): void {
    phaseTenReadyUser($this);
    ProviderCardholder::query()->delete();
    $provider = Mockery::mock(CardProviderInterface::class);
    $provider->shouldReceive('available')->andReturn(true);
    $provider->shouldReceive('name')->andReturn('PHOTONPAY');
    $provider->shouldReceive('createCardholder')->once()->andReturnUsing(function ($request) {
        expect($request->residentialCountryCode)->toBe('CN')
            ->and($request->residentialState)->toBe('Anhui')
            ->and($request->residentialCity)->toBe('Fuyang')
            ->and($request->mobile)->toBe('2025550123')
            ->and($request->mobilePrefix)->toBe('1');

        return (new MockCardProvider)->createCardholder($request);
    });
    app()->instance(CardProviderInterface::class, $provider);
    $data = array_replace(independentCardMaterials($this), ['residential_country_code' => 'CN', 'residential_state' => 'Anhui', 'residential_city' => 'Fuyang', 'mobile' => '202-555-0123', 'mobile_country_code' => 'US']);
    $holder = app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, $data);
    $fields = json_decode(app(CardholderMaterials::class)->decrypt($holder->materials_encrypted), true)['fields'];
    expect($fields['mobile'])->toBe('2025550123')
        ->and($holder->toArray())->not->toHaveKey('materials_encrypted')
        ->and(CardIssueOrder::query()->count())->toBe(0)
        ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe('100.00000000');
});

it('rejects same-tenant other-user and other-product holder applications before holds', function (): void {
    phaseTenReadyUser($this);
    $otherUser = $this->user->replicate(['account_id']);
    $otherUser->forceFill(['email' => 'different-holder-owner@example.test', 'phone' => null])->save();
    $scope = (object) ['tenant' => $this->tenant, 'user' => $otherUser, 'product' => $this->product];
    $foreign = phaseTenHolder($scope);
    expect(fn () => app(CreateCardIssueAction::class)->execute($this->tenant->id, $this->user->id, (string) Str::uuid(), $this->product->id, '20', $foreign->id))->toThrow(DomainException::class)
        ->and(fn () => app(SyncProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, $foreign->id))->toThrow(ModelNotFoundException::class);
    $otherProduct = $this->product->replicate();
    $otherProduct->forceFill(['name' => 'Other regular product', 'provider_product_ref' => 'MOCK-OTHER-PRODUCT'])->save();
    $scope = (object) ['tenant' => $this->tenant, 'user' => $this->user, 'product' => $otherProduct];
    $wrongProduct = phaseTenHolder($scope);
    expect(fn () => app(CreateCardIssueAction::class)->execute($this->tenant->id, $this->user->id, (string) Str::uuid(), $this->product->id, '20', $wrongProduct->id))->toThrow(DomainException::class)
        ->and(CardIssueOrder::query()->count())->toBe(0)
        ->and(phaseTenAccount($this, LedgerAccountType::UserAvailable)->balance)->toBe('100.00000000');
});

/** Create through the real business/ledger flow with an isolated test provider result, never by rewriting a legacy card ID. */
function transactionReadFixture($test): array
{
    phaseTenReadyUser($test);
    $provider = Mockery::mock(CardProviderInterface::class);
    $provider->shouldReceive('available')->andReturn(true);
    $provider->shouldReceive('name')->andReturn('PHOTONPAY');
    $provider->shouldReceive('productAvailable')->andReturn(true);
    $provider->shouldReceive('issueCard')->once()->andReturnUsing(function ($request): ProviderOperationDTO {
        $card = new ProviderCardDTO('XR-TRANSACTION-FIXTURE', '', '•••• 5678', '5678', 8, 2029, 'USD', 'normal', false, '20.00000000');

        return new ProviderOperationDTO($request->idempotencyKey, ProviderOperationStatus::Succeeded, $card->providerCardId, card: $card);
    });
    app()->instance(CardProviderInterface::class, $provider);
    $order = phaseTenIssue($test);
    $card = UserCard::query()->where('tenant_id', $test->tenant->id)->where('user_id', $test->user->id)->where('card_issue_order_id', $order->id)->firstOrFail();

    return [$card, $provider];
}

it('syncs scoped transaction pages and reads stored data without changing card wallet or ledger data', function (): void {
    [$card, $provider] = transactionReadFixture($this);
    $baseline = DB::transactionLevel();
    $provider->shouldReceive('getTransactionPage')->with('XR-TRANSACTION-FIXTURE', 1, 20)->once()
        ->andReturnUsing(function () use ($baseline): ProviderTransactionPageDTO {
            expect(DB::transactionLevel())->toBe($baseline);

            return new ProviderTransactionPageDTO([
                new ProviderCardTransactionDTO('IT-PRIVATE-REFERENCE', '12.34000000', 'EUR', 'purchase', 'completed', '2026-09-11T09:05:04', 'Example shop'),
            ], 1, true);
        });
    $provider->shouldReceive('getTransactionPage')->with('XR-TRANSACTION-FIXTURE', 2, 20)->once()->andReturn(new ProviderTransactionPageDTO([], 2, false));
    $tables = ['users', 'user_cards', 'card_issue_orders', 'provider_cardholders', 'wallets', 'ledger_accounts', 'ledger_entries', 'ledger_postings'];
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]);
    $response = $this->actingAs($this->user, 'tenant_user')->postJson("http://a.localhost/cards/{$card->id}/transactions/sync", ['page' => 1])
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('hasMore', true)
        ->assertJsonPath('items.0.cardId', $card->id)->assertJsonPath('items.0.last4', '5678')->assertJsonPath('items.0.amount', '12.34000000');
    expect($response->getContent())->not->toContain('IT-PRIVATE-REFERENCE', 'XR-TRANSACTION-FIXTURE', 'provider_card_id', 'tenant_id', 'cvv');
    $this->postJson("http://a.localhost/cards/{$card->id}/transactions/sync", ['page' => 2])->assertOk()->assertExactJson(['items' => [], 'page' => 2, 'hasMore' => false]);
    $this->getJson("http://a.localhost/cards/{$card->id}/transactions?page=1")->assertOk()->assertJsonPath('items.0.timeKind', 'recorded')->assertJsonMissingPath('items.0.occurredAt');
    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($before[$table]);
    }
});

it('checks card tenant user ownership and request selectors before transaction provider calls', function (): void {
    [$card, $provider] = transactionReadFixture($this);
    $provider->shouldNotReceive('getTransactionPage');
    $otherTenant = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    expect(fn () => app(UserCardTransactionsQuery::class)->get($otherTenant->id, $this->user->id, $card->id, 1))->toThrow(ModelNotFoundException::class);
    $other = $this->user->replicate(['account_id']);
    $other->forceFill(['email' => 'transaction-reader@example.test', 'phone' => null])->save();
    $this->actingAs($other, 'tenant_user')->getJson("http://a.localhost/cards/{$card->id}/transactions")->assertNotFound();
    $this->actingAs($this->user, 'tenant_user')->getJson("http://a.localhost/cards/{$card->id}/transactions?tenant_id={$otherTenant->id}&provider_card_id=XR-FOREIGN")
        ->assertUnprocessable()->assertJsonValidationErrors(['tenant_id', 'provider_card_id']);
    foreach (['0', '-1', '1.5', '100001'] as $page) {
        $this->getJson("http://a.localhost/cards/{$card->id}/transactions?page={$page}")->assertUnprocessable()->assertJsonValidationErrors('page');
    }
    $this->user->forceFill(['status' => 'SUSPENDED'])->save();
    $this->getJson("http://a.localhost/cards/{$card->id}/transactions")->assertRedirect('/account/restricted');
});

it('returns a safe unavailable transaction response for provider failures instead of an empty success', function (): void {
    [$card, $provider] = transactionReadFixture($this);
    $provider->shouldReceive('getTransactionPage')->once()->andThrow(new ProviderUnknownResultException('private-provider-error-with-card-data'));
    $balance = $card->provider_balance;
    $response = $this->actingAs($this->user, 'tenant_user')->postJson("http://a.localhost/cards/{$card->id}/transactions/sync")->assertStatus(503);
    expect($response->getContent())->not->toContain('private-provider-error-with-card-data')->and($card->fresh()->provider_balance)->toBe($balance);
});

it('never uses a historical mock card reference to read production transaction history', function (): void {
    phaseTenReadyUser($this);
    $order = phaseTenIssue($this);
    $card = UserCard::query()->where('tenant_id', $this->tenant->id)->where('card_issue_order_id', $order->id)->firstOrFail();
    $provider = Mockery::mock(CardProviderInterface::class);
    $provider->shouldReceive('available')->andReturn(true);
    $provider->shouldReceive('name')->andReturn('PHOTONPAY');
    $provider->shouldNotReceive('getTransactionPage');
    app()->instance(CardProviderInterface::class, $provider);
    $this->actingAs($this->user, 'tenant_user')->postJson("http://a.localhost/cards/{$card->id}/transactions/sync")->assertStatus(503);
});

it('snapshots the SaaS price and preserves completed replay after a platform price change', function (): void {
    phaseTenReadyUser($this, '100');
    $this->product->forceFill(['opening_fee' => '7.00000000'])->save();
    $request = (string) Str::uuid();
    $order = phaseTenIssue($this, '20.00', $request);
    expect($order->opening_fee)->toBe('7.00000000');
    $entries = LedgerEntry::query()->count();
    $this->product->forceFill(['opening_fee' => '9.00000000'])->save();
    expect(phaseTenIssue($this, '20.00', $request)->id)->toBe($order->id)
        ->and($order->fresh()->opening_fee)->toBe('7.00000000')
        ->and(LedgerEntry::query()->count())->toBe($entries);
});

it('shows confirmed platform card balances and refreshes only the selected company card without ledger changes', function (): void {
    [$card] = managedCardFixture($this);
    $platform = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $entries = LedgerEntry::query()->count();
    $other = Tenant::query()->where('id', '!=', $this->tenant->id)->firstOrFail();
    $this->actingAs($platform, 'platform_admin')->get('http://admin.localhost/platform/cards?tab=cards')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('cards.data.0.balance', $card->provider_balance)
        ->where('cards.data.0.currency', 'USD')->has('cards.data.0.balanceUpdatedAt'));
    $this->post('http://admin.localhost/platform/tenants/'.$other->id.'/cards/'.$card->id.'/refresh', ['tenant_id' => $this->tenant->id])->assertNotFound();
    $this->from('http://admin.localhost/platform/cards?tab=cards')->post('http://admin.localhost/platform/tenants/'.$this->tenant->id.'/cards/'.$card->id.'/refresh')
        ->assertRedirect('http://admin.localhost/platform/cards?tab=cards')->assertSessionHasNoErrors();
    expect(LedgerEntry::query()->count())->toBe($entries)->and($card->fresh()->provider_balance_synced_at)->not->toBeNull();
});

it('counts card capacity independently for each product during holder submission and issue', function (): void {
    Http::preventStrayRequests();
    phaseTenReadyUser($this, '200');
    $config = TenantCardProductConfig::query()->where('tenant_id', $this->tenant->id)->where('card_product_id', $this->product->id)->sole();
    $config->forceFill(['max_cards_per_user' => 1])->save();
    $first = phaseTenIssue($this);
    $secondProduct = $this->product->replicate();
    $secondProduct->forceFill(['name' => 'Independent capacity product', 'provider_product_ref' => 'MOCK-SECOND-CAPACITY-PRODUCT'])->save();
    $secondConfig = $config->replicate();
    $secondConfig->forceFill(['card_product_id' => $secondProduct->id])->save();
    $this->product = $secondProduct;
    $this->holder = app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, independentCardMaterials($this));
    expect($this->holder->status)->toBe(ProviderCardholderStatus::Ready);
    $second = phaseTenIssue($this);
    expect($first->status)->toBe(CardIssueStatus::Succeeded)
        ->and($second->status)->toBe(CardIssueStatus::Succeeded)
        ->and($second->card_product_id)->toBe($secondProduct->id);
    expect(fn () => app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, independentCardMaterials($this, 'Bob')))->toThrow(DomainException::class, 'maximum');
    Http::assertNothingSent();
});

it('preserves first recorded time across duplicate syncs and ignores overlapping older observations', function (): void {
    [$card] = transactionReadFixture($this);
    $record = app(RecordCardTransactionsAction::class);
    $make = fn ($state) => new ProviderCardTransactionDTO('RECORD-STABLE', '12.34000000', 'USD', 'purchase', $state, '2020-01-01T09:00:00', null);
    $this->travelTo(CarbonImmutable::parse('2026-09-15T15:59:59.123456Z'));
    $record->execute($card, [$make('pending')], CarbonImmutable::now());
    $first = CardTransaction::query()->where('card_id', $card->id)->firstOrFail();
    $firstTime = $first->created_at->format('Y-m-d H:i:s.u');
    $oldStart = CarbonImmutable::now();
    $this->travelTo(CarbonImmutable::parse('2026-09-15T16:00:00.654321Z'));
    $record->execute($card, [$make('completed')], CarbonImmutable::now());
    $record->execute($card, [$make('pending')], $oldStart);
    expect(CardTransaction::query()->where('card_id', $card->id)->count())->toBe(1)
        ->and($first->fresh()->created_at->format('Y-m-d H:i:s.u'))->toBe($firstTime)
        ->and($first->fresh()->state)->toBe('completed');
    $view = app(UserCardTransactionsQuery::class)->get($this->tenant->id, $this->user->id, $card->id, 1);
    expect($view['items'][0]['timeKind'])->toBe('recorded')->and($view['items'][0]['displayAt'])->toBe('2026-09-15T15:59:59+00:00');
});

it('uses an exact transaction match for completion time and never matches only amount', function (): void {
    [$card, $provider, $action] = managedCardFixture($this);
    $provider->shouldReceive('quoteCardLoad')->once()->andReturnUsing(fn ($id, $amount, $request) => new ProviderCardQuoteDTO($request, '20.00000000', '20.00000000', '0.00000000'));
    $provider->shouldReceive('confirmCardLoad')->once()->andReturnUsing(fn ($id, $request) => new ProviderCardFundsDTO(ProviderOperationStatus::Succeeded, $id, $request, 'TX-SYSTEM-TIME', '20.00000000', '20.00000000', '0.00000000'));
    $order = $action->quote($this->tenant->id, $this->user->id, $card->id, (string) Str::uuid(), '20');
    $settled = $action->confirmLoad($this->tenant->id, $this->user->id, $card->id, $order->id);
    $entry = LedgerEntry::query()->findOrFail($settled->settlement_entry_id);
    $this->travel(1)->hours();
    app(RecordCardTransactionsAction::class)->execute($card, [
        new ProviderCardTransactionDTO('TX-SYSTEM-TIME', '20.00000000', 'USD', 'transfer_in', 'completed', '2020-01-01T00:00:00', null),
        new ProviderCardTransactionDTO('SAME-AMOUNT-DIFFERENT-TX', '20.00000000', 'USD', 'transfer_in', 'completed', '2020-01-01T00:00:00', null),
    ], CarbonImmutable::now());
    $items = collect(app(UserCardTransactionsQuery::class)->get($this->tenant->id, $this->user->id, $card->id, 1)['items']);
    expect($items->where('timeKind', 'completed'))->toHaveCount(1)
        ->and($items->firstWhere('timeKind', 'completed')['displayAt'])->toBe($entry->posted_at->utc()->toIso8601String())
        ->and($items->where('timeKind', 'recorded'))->toHaveCount(1);
});

it('keeps stored records readable after sync fails and blocks foreign sync selectors', function (): void {
    [$card, $provider] = transactionReadFixture($this);
    app(RecordCardTransactionsAction::class)->execute($card, [
        new ProviderCardTransactionDTO('OFFLINE-STORED', '0.01000000', 'USD', 'purchase', 'pending', '2020-01-01T00:00:00', null),
    ], CarbonImmutable::now());
    $other = $this->user->replicate(['account_id']);
    $other->forceFill(['email' => 'sync-foreign@example.test', 'phone' => null])->save();
    $this->actingAs($other, 'tenant_user')->postJson("http://a.localhost/cards/{$card->id}/transactions/sync")->assertNotFound();
    $this->actingAs($this->user, 'tenant_user')->postJson("http://a.localhost/cards/{$card->id}/transactions/sync", ['tenant_id' => $this->tenant->id])->assertUnprocessable();
    $provider->shouldReceive('getTransactionPage')->once()->andThrow(new ProviderUnknownResultException('secret'));
    $this->postJson("http://a.localhost/cards/{$card->id}/transactions/sync")->assertStatus(503);
    $this->getJson("http://a.localhost/cards/{$card->id}/transactions")->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.amount', '0.01000000');
});

it('lets SaaS read scoped paginated stored card transactions without provider or financial writes', function (): void {
    [$card, $provider] = transactionReadFixture($this);
    $provider->shouldNotReceive('getTransactionPage');
    $provider->shouldNotReceive('getTransactions');
    $provider->shouldNotReceive('getCard');
    $this->tenant->update(['timezone' => 'Asia/Shanghai']);
    $rows = [];
    for ($i = 0; $i < 21; $i++) {
        $rows[] = new ProviderCardTransactionDTO('PLATFORM-PRIVATE-'.$i, '123456789012.12345678', 'USD', 'purchase', 'completed', '2020-01-01T00:00:00', 'Example shop');
    }
    app(RecordCardTransactionsAction::class)->execute($card, $rows, CarbonImmutable::now());
    $card->forceFill(['provider_status' => 'cancelled'])->save();
    $tables = ['user_cards', 'card_transactions', 'card_management_orders', 'wallets', 'ledger_accounts', 'ledger_entries', 'ledger_postings'];
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]);
    $platform = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $url = "http://admin.localhost/platform/tenants/{$this->tenant->id}/cards/{$card->id}/transactions";
    $first = $this->actingAs($platform, 'platform_admin')->getJson($url)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(20, 'items')
        ->assertJsonPath('timezone', 'Asia/Shanghai')->assertJsonPath('page', 1)->assertJsonPath('hasMore', true)
        ->assertJsonPath('items.0.amount', '123456789012.12345678')->assertJsonPath('items.0.timeKind', 'recorded');
    expect($first->getContent())->not->toContain('PLATFORM-PRIVATE-', 'XR-TRANSACTION-FIXTURE', 'provider_card_id', 'provider_transaction_id', 'cvv', 'tenant_id', 'user_id');
    $second = $this->getJson($url.'?page=2')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('hasMore', false);
    expect(array_intersect(array_column($first->json('items'), 'id'), array_column($second->json('items'), 'id')))->toBe([]);
    $this->getJson($url.'?page=3')->assertOk()->assertJsonCount(0, 'items');
    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($before[$table]);
    }
});

it('protects SaaS card transaction reads with company scoping and active cards read permission', function (): void {
    [$card] = transactionReadFixture($this);
    $platform = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $other = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $url = "http://admin.localhost/platform/tenants/{$this->tenant->id}/cards/{$card->id}/transactions";
    $this->getJson($url)->assertRedirect('/platform/login');
    $this->actingAs($this->owner, 'platform_admin')->getJson($url)->assertForbidden();
    $this->actingAs($platform, 'platform_admin');
    $this->getJson("http://admin.localhost/platform/tenants/{$other->id}/cards/{$card->id}/transactions")->assertNotFound();
    $this->getJson("http://admin.localhost/platform/tenants/{$this->tenant->id}/cards/".Str::uuid().'/transactions')->assertNotFound();
    foreach (['page=0', 'page=100001', 'page=1.5', 'tenant_id='.$other->id, 'user_id='.$this->user->id, 'provider_card_id=other', 'page_size=1000'] as $invalid) {
        $this->getJson($url.'?'.$invalid)->assertUnprocessable();
    }
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'items');
    $platform->update(['status' => 'SUSPENDED']);
    $this->getJson($url)->assertForbidden();
    $platform->update(['status' => 'ACTIVE']);
    $this->actingAs($platform->fresh(), 'platform_admin');
    DB::table('admin_memberships')->where('admin_user_id', $platform->id)->update(['status' => 'SUSPENDED']);
    $this->getJson($url)->assertForbidden();
    DB::table('admin_memberships')->where('admin_user_id', $platform->id)->update(['status' => 'ACTIVE']);
    DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('name', 'cards.read')->value('id'))->delete();
    $this->actingAs($platform->fresh(), 'platform_admin')->getJson($url)->assertForbidden();
});
