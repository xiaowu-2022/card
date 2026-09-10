<?php

use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\CardProvider\DTOs\IssueCardRequestDTO;
use App\Domain\CardProvider\Enums\MockProviderMode;
use App\Domain\CardProvider\Enums\ProviderOperationStatus;
use App\Domain\CardProvider\Exceptions\ProviderRejectedException;
use App\Domain\CardProvider\Exceptions\ProviderUnknownResultException;
use App\Infrastructure\Providers\Card\MockCardProvider;

$request = fn () => new IssueCardRequestDTO('TEST-PRODUCT', 'TEST-HOLDER', 'USD', '20.00', 'test-request-1');

it('implements the shared provider contract and succeeds in success mode', function () use ($request): void {
    $provider = new MockCardProvider(MockProviderMode::Success);
    $operation = $provider->issueCard($request());

    expect($provider)->toBeInstanceOf(CardProviderInterface::class)
        ->and($operation->status)->toBe(ProviderOperationStatus::Succeeded)
        ->and($operation->resourceId)->toStartWith('MOCK-CARD-');
});

it('exposes every locked card provider capability', function (): void {
    $methods = get_class_methods(CardProviderInterface::class);

    expect($methods)->toContain(
        'issueCard',
        'createCardholder',
        'getCardholder',
        'productAvailable',
        'getCard',
        'revealCard',
        'loadCard',
        'freezeCard',
        'unfreezeCard',
        'cancelCard',
        'getBalance',
        'getTransactions',
        'queryOperation',
    );
});

it('rejects in failed mode', fn () => (new MockCardProvider(MockProviderMode::Failed))->issueCard($request()))
    ->throws(ProviderRejectedException::class);

it('maps timeout to unknown result rather than failed', fn () => (new MockCardProvider(MockProviderMode::Timeout))->issueCard($request()))
    ->throws(ProviderUnknownResultException::class, 'UNKNOWN');

it('returns an explicit unknown operation in unknown mode', function () use ($request): void {
    $operation = (new MockCardProvider(MockProviderMode::Unknown))->issueCard($request());
    expect($operation->status)->toBe(ProviderOperationStatus::Unknown);
});

it('uses unmistakably non-production sensitive display values', function (): void {
    $card = (new MockCardProvider)->getCard('MOCK-CARD-1');
    $revealed = (new MockCardProvider)->revealCard('MOCK-CARD-1');

    expect($card->isTest)->toBeTrue()
        ->and($card->maskedPan)->toContain('TEST')
        ->and($revealed->displayPan)->toBe('TEST-MOCK-NOT-A-PAN')
        ->and($revealed->displayCvv)->toBe('MOCK');
});
