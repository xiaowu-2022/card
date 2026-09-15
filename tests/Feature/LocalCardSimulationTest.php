<?php

use App\Application\Tenant\UpdateTenantLocalesAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\CardProvider\DTOs\CardholderRequestDTO;
use App\Domain\CardProvider\DTOs\CardholderUpdateDTO;
use App\Domain\CardProvider\DTOs\IssueCardRequestDTO;
use App\Domain\CardProvider\DTOs\ProviderIdentityDocumentDTO;
use App\Domain\CardProvider\Enums\ProviderOperationStatus;
use App\Domain\CardProvider\Exceptions\ProviderRateLimitException;
use App\Domain\CardProvider\Exceptions\ProviderRejectedException;
use App\Domain\CardProvider\Exceptions\ProviderUnknownResultException;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Providers\Card\LocalCardSimulation;
use App\Infrastructure\Providers\Card\LocalMockCardProvider;
use App\Infrastructure\Providers\Card\UnavailableCardProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['card-provider.driver' => 'mock', 'card-provider.mock_mode' => 'SUCCESS', 'card-provider.mock_cardholder_mode' => 'READY']);
    app()->detectEnvironment(fn () => 'local');
    app()->forgetInstance(CardProviderInterface::class);
    $this->provider = app(CardProviderInterface::class);
});

afterEach(function (): void {
    Http::assertNothingSent();
    app()->detectEnvironment(fn () => 'testing');
    app()->forgetInstance(CardProviderInterface::class);
});

function simulatorHolderRequest(?string $id = null): CardholderRequestDTO
{
    return new CardholderRequestDTO('Test', 'Holder', '1990-01-01', 'test@example.test', null, null,
        'MY', 'Test Road', 'Test City', 'Test State', 'MY', '50000',
        new ProviderIdentityDocumentDTO('id_card', 'MY', null, 'PRIVATE-TEST-DOCUMENT', 'image/png', null, null), $id);
}

it('initializes every consumer language for fresh mock companies without overwriting later settings', function (): void {
    $this->seed();
    foreach (Tenant::query()->whereIn('slug', ['tenant-a', 'tenant-b'])->get() as $tenant) {
        expect($tenant->locales()->where('enabled', true)->orderBy('locale')->pluck('locale')->all())
            ->toBe(['en', 'es', 'ms', 'zh-CN']);
        expect($tenant->locales()->where('is_default', true)->pluck('locale')->all())->toBe(['en']);
    }
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $owner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    app(UpdateTenantLocalesAction::class)->execute($tenant, ['en', 'zh-CN', 'ms'], 'zh-CN', $owner);
    $this->seed();
    expect($tenant->fresh()->locales()->where('enabled', true)->orderBy('locale')->pluck('locale')->all())->toBe(['en', 'ms', 'zh-CN'])
        ->and($tenant->fresh()->default_locale)->toBe('zh-CN');
});

function simulatorCard($test): string
{
    $holder = $test->provider->createCardholder(simulatorHolderRequest());
    $test->simHolder = $holder->providerCardholderId;

    return $test->provider->issueCard(new IssueCardRequestDTO('TEST-PRODUCT', $test->simHolder, 'USD', '20.00', 'issue-1'))->resourceId;
}

it('selects an explicit local simulator only on an isolated database', function (): void {
    expect(LocalCardSimulation::active())->toBeTrue()->and($this->provider)->toBeInstanceOf(LocalMockCardProvider::class);
    $connection = DB::connection();
    $original = $connection->getDatabaseName();
    try {
        $connection->setDatabaseName('card_platform');
        expect(LocalCardSimulation::enabled())->toBeFalse()
            ->and(app(CardProviderInterface::class))->toBeInstanceOf(UnavailableCardProvider::class);
    } finally {
        $connection->setDatabaseName($original);
    }
});

it('refuses local simulation in every nonlocal runtime even with the explicit driver', function (string $env): void {
    app()->detectEnvironment(fn () => $env);
    expect(LocalCardSimulation::enabled())->toBeFalse()->and($this->provider->available())->toBeFalse()
        ->and(app(CardProviderInterface::class))->toBeInstanceOf(UnavailableCardProvider::class);
})->with(['production', 'staging']);

it('covers the full shared card contract with durable exact state and idempotent operations', function (): void {
    $p = $this->provider;
    expect($p->name())->toBe('PHOTONPAY')->and($p->available())->toBeTrue()
        ->and($p->productAvailable('TEST-PRODUCT', 'USD'))->toBeTrue()
        ->and($p->productAvailable('LIVE-PRODUCT', 'USD'))->toBeFalse()
        ->and($p->productAvailable('TEST-PRODUCT', 'EUR'))->toBeFalse();
    $id = simulatorCard($this);
    expect($p->getCardholder($this->simHolder)->providerCardholderId)->toBe($this->simHolder);
    $p->updateCardholder(simulatorHolderRequest($this->simHolder));
    $fields = new CardholderUpdateDTO($this->simHolder, ['email' => 'changed@example.test']);
    $p->editCardholderFields($fields);
    expect((new LocalMockCardProvider)->cardholderFieldsMatch($fields))->toBeTrue()
        ->and($p->cardholderFieldsMatch(new CardholderUpdateDTO($this->simHolder, ['email' => 'wrong@example.test'])))->toBeFalse();
    expect($p->getCard($id)->isTest)->toBeTrue()->and($p->revealCard($id)->displayCvv)->toBe('000');
    expect($p->quoteCardLoad($id, '20.01', 'load-1')->debitAmount)->toBe('20.01000000');
    $p->confirmCardLoad($id, 'load-1');
    $p->confirmCardLoad($id, 'load-1');
    expect($p->queryCardFunds($id, 'load-1', 'LOAD')->status)->toBe(ProviderOperationStatus::Succeeded)
        ->and((new LocalMockCardProvider)->getBalance($id)->amount)->toBe('40.01000000');
    $p->loadCard($id, '1.00000001', 'USD', 'legacy-load');
    $p->returnCardFunds($id, '0.01000001', 'return-1');
    $p->returnCardFunds($id, '0.01000001', 'return-1');
    expect($p->queryCardFunds($id, 'return-1', 'RETURN')->arrivalAmount)->toBe('0.01000001')
        ->and($p->getBalance($id)->amount)->toBe('41.00000000');
    $p->freezeCard($id, 'freeze-1');
    expect($p->getCard($id)->status)->toBe('frozen');
    $p->unfreezeCard($id, 'unfreeze-1');
    expect($p->getCard($id)->status)->toBe('normal');
    $tx = $p->simulatePurchase($id, 'purchase-1', '3.25');
    $p->simulatePurchase($id, 'purchase-1', '3.25');
    expect($p->getTransaction($id, $tx)->amount)->toBe('3.25000000')
        ->and($p->getBalance($id)->amount)->toBe('37.75000000')
        ->and($p->getTransactions($id))->toHaveCount(4)
        ->and($p->getTransactionPage($id, 1, 2)->hasMore)->toBeTrue()
        ->and($p->getTransactionPage($id, 2, 2)->hasMore)->toBeFalse();
    $p->cancelCard($id, 'cancel-1');
    $p->cancelCard($id, 'cancel-1');
    expect($p->queryOperation('cancel-1')->status)->toBe(ProviderOperationStatus::Succeeded)
        ->and($p->getCard($id)->status)->toBe('cancelled')->and($p->getBalance($id)->amount)->toBe('0.00000000');
    $return = $p->getTransactionPage($id, 1, 20)->items[0];
    expect($p->queryCardFunds($id, $return->providerTransactionId, 'CANCEL_RETURN')->arrivalAmount)->toBe('37.75000000');
    $payload = DB::table('local_card_simulator_states')->value('payload');
    expect($payload)->not->toContain('changed@example.test', 'PRIVATE-TEST-DOCUMENT', 'TEST-MOCK-NOT-A-PAN');
});

it('keeps uncertain money unchanged until querying the same request confirms an outcome', function (string $mode): void {
    $id = simulatorCard($this);
    $this->provider->quoteCardLoad($id, '20', 'pending-load');
    config(['card-provider.mock_mode' => $mode]);
    try {
        $result = $this->provider->confirmCardLoad($id, 'pending-load');
    } catch (ProviderUnknownResultException|ProviderRateLimitException) {
        $result = null;
    }
    expect($this->provider->getBalance($id)->amount)->toBe('20.00000000');
    if ($result) {
        expect($result->status)->not->toBe(ProviderOperationStatus::Succeeded);
    }
    config(['card-provider.mock_mode' => 'SUCCESS']);
    $result = $this->provider->queryCardFunds($id, 'pending-load', 'LOAD');
    $failed = $mode === 'DELAYED_FAILURE';
    expect($result->status)->toBe($failed ? ProviderOperationStatus::Failed : ProviderOperationStatus::Succeeded)
        ->and($this->provider->getBalance($id)->amount)->toBe($failed ? '20.00000000' : '40.00000000');
    $this->provider->queryCardFunds($id, 'pending-load', 'LOAD');
    expect($this->provider->getBalance($id)->amount)->toBe($failed ? '20.00000000' : '40.00000000');
})->with(['UNKNOWN', 'TIMEOUT', 'RATE_LIMIT', 'DELAYED_SUCCESS', 'DELAYED_FAILURE', 'DUPLICATE_WEBHOOK']);

it('rejects definitive failures without changing provider state', function (): void {
    $id = simulatorCard($this);
    config(['card-provider.mock_mode' => 'FAILED']);
    expect(fn () => $this->provider->returnCardFunds($id, '1', 'failed-return'))->toThrow(ProviderRejectedException::class);
    expect($this->provider->queryCardFunds($id, 'failed-return', 'RETURN')->status)->toBe(ProviderOperationStatus::Failed)
        ->and($this->provider->getBalance($id)->amount)->toBe('20.00000000');
});

it('never imports real or historical mock identities and rejects ownership or request mismatches', function (): void {
    $id = simulatorCard($this);
    expect(fn () => $this->provider->getCard('REAL-CARD'))->toThrow(ProviderRejectedException::class);
    expect(fn () => $this->provider->getCard('MOCK-CARD-HISTORICAL'))->toThrow(ProviderRejectedException::class);
    expect(fn () => $this->provider->getCardholder('MOCK-HOLDER-HISTORICAL'))->toThrow(ProviderRejectedException::class);
    $this->provider->quoteCardLoad($id, '2', 'one-request');
    expect(fn () => $this->provider->quoteCardLoad($id, '3', 'one-request'))->toThrow(ProviderRejectedException::class);
    $this->provider->confirmCardLoad($id, 'one-request');
    expect(fn () => $this->provider->returnCardFunds($id, '2', 'one-request'))->toThrow(ProviderRejectedException::class);
    expect(fn () => $this->provider->queryCardFunds('OTHER', 'one-request', 'LOAD'))->toThrow(ProviderUnknownResultException::class);
    expect(fn () => $this->provider->returnCardFunds($id, '100', 'too-much'))->toThrow(ProviderRejectedException::class);
    expect($this->provider->getBalance($id)->amount)->toBe('22.00000000');
});

it('rejects invalid decimal amounts', function (string $amount): void {
    $id = simulatorCard($this);
    expect(fn () => $this->provider->returnCardFunds($id, $amount, 'invalid'))->toThrow(ProviderRejectedException::class);
})->with(['0', '-1', '1.000000001', 'invalid', '1000000000000']);
