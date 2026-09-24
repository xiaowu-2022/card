<?php

use App\Domain\CardProvider\DTOs\IssueCardRequestDTO;
use App\Domain\CardProvider\Exceptions\ProviderRejectedException;
use App\Domain\CardProvider\Exceptions\ProviderUnknownResultException;
use App\Infrastructure\Providers\Card\PhotonPayCardProvider;
use App\Infrastructure\Providers\Card\PhotonPayCardResponseNormalizer;
use App\Support\Logging\SensitiveDataRedactor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function physicalAdapter(): PhotonPayCardProvider
{
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $private);
    Http::preventStrayRequests();

    return (new PhotonPayCardProvider('https://x-api.photonpay.com', 'test-app', 'test-secret', $private, 'USD-ACCOUNT', 'MEMBER', null, 10, new PhotonPayCardResponseNormalizer))->forFormFactor('physical_card');
}

it('requires matching form factor and recipient and sends a physical issue payload', function () {
    $provider = physicalAdapter();
    Http::fake([
        '*getCardBin*' => Http::response(['code' => '0000', 'data' => [['cardBin' => '53493435', 'cardScheme' => 'MasterCard', 'cardType' => 'recharge', 'cardCurrency' => 'USD', 'cardFormFactor' => 'virtual_card,physical_card']]]),
        '*openCard' => Http::response(['code' => '0000', 'data' => ['requestId' => 'stable-id', 'status' => 'succeed', 'cardDetail' => ['cardId' => 'XR-physical', 'cardType' => 'recharge', 'cardCurrency' => 'USD', 'cardFormFactor' => 'physical_card', 'maskCardNo' => '**** 1234', 'cardBalance' => '20', 'cardStatus' => 'unactivated', 'produceStatus' => 'pending']]]),
    ]);
    expect(fn () => $provider->issueCard(new IssueCardRequestDTO('53493435', 'CH-1', 'USD', '20', 'bad', 'physical_card')))->toThrow(ProviderRejectedException::class);
    $result = $provider->issueCard(new IssueCardRequestDTO('53493435', 'CH-1', 'USD', '20', 'stable-id', 'physical_card', 'RI-1'));
    expect($result->card->formFactor)->toBe('physical_card')->and($result->card->status)->toBe('unactivated');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/openCard') && $r['recipientId'] === 'RI-1' && $r['cardFormFactor'] === 'physical_card');
});

it('rejects virtual detail on physical context and accepts actual production metadata', function () {
    $n = new PhotonPayCardResponseNormalizer;
    $row = ['cardId' => 'XR-1', 'cardType' => 'recharge', 'cardCurrency' => 'USD', 'cardFormFactor' => 'virtual_card', 'maskCardNo' => '**** 1234'];
    expect($n->normalize($row, expectedFormFactor: 'physical_card'))->toBeNull();
    $row['cardFormFactor'] = 'physical_card';
    $row['cardStatus'] = 'unactivated';
    $row['produceStatus'] = 'produced';
    $row['trackingNumber'] = 'TEST-123';
    expect($n->normalize($row, expectedFormFactor: 'physical_card')->trackingNumber)->toBe('TEST-123');
    expect($n->normalize($row))->toBeNull();
});

it('creates and verifies only the exact recipient in the same merchant', function () {
    $p = physicalAdapter();
    Http::fake([
        '*addRecipient' => Http::response(['code' => '0000', 'data' => ['recipientId' => 'RI-1', 'recipientStatus' => 'Normal']]),
        '*pagingRecipient*' => Http::response(['code' => '0000', 'data' => [['recipientId' => 'RI-1', 'recipientStatus' => 'Normal', 'memberId' => 'MEMBER']]]),
    ]);
    expect($p->addRecipient(['recipientFirstName' => 'Sandbox']))->toBe('RI-1');
    expect($p->recipientAvailable('RI-1'))->toBeTrue()->and($p->recipientAvailable('RI-other'))->toBeFalse();
});

it('does not infer successful recipient creation from an empty response or retry', function () {
    $p = physicalAdapter();
    Http::fake(['*' => Http::response(['code' => '0000'])]);
    expect(fn () => $p->addRecipient(['recipientFirstName' => 'Sandbox']))->toThrow(ProviderUnknownResultException::class);
    Http::assertSentCount(1);
});

it('sends activation PIN only in the signed body and redacts all PIN field variants', function () {
    $p = physicalAdapter();
    Http::fake(['*' => Http::response(['code' => '0000'])]);
    $p->activatePhysicalCard('XR-1', '05/29', '123456', '123456');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/activateCard') && $r['pin'] === '123456' && $r['pinConfirm'] === '123456' && $r->hasHeader('X-PD-SIGN'));
    $r = (new SensitiveDataRedactor)->redact(['pin' => '123456', 'pin_confirmation' => '123456', 'pinConfirm' => '123456', 'recipientFirstName' => 'PRIVATE', 'addressLine1' => 'PRIVATE']);
    expect(array_unique(array_values($r)))->toBe(['[REDACTED]']);
});
