<?php

use App\Application\Card\CardSensitiveDetails;
use App\Domain\Card\Models\UserCard;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['card-provider.offline' => true]);
    Http::preventStrayRequests();
});

it('rejects missing offline details before looking up a provider or card product', function () {
    $card = (new UserCard)->forceFill(['provider_status' => 'normal']);
    $card->setRelation('product', null);
    expect(fn () => app(CardSensitiveDetails::class)->acquire($card))
        ->toThrow(fn (DomainException $error) => expect($error->errorCode)->toBe('CARD_DETAILS_OFFLINE'));
    Http::assertNothingSent();
});

it('reads a matching saved encrypted card offline without calling providers', function () {
    $card = new UserCard;
    $card->forceFill(['id' => 'offline-card', 'tenant_id' => 'offline-company', 'user_id' => 'offline-user',
        'provider' => 'PHOTONPAY', 'provider_card_id' => 'offline-provider-card', 'last4' => '1111', 'provider_status' => 'normal']);
    $card->sensitive_details_encrypted = Crypt::encryptString(json_encode([
        'identity' => ['offline-card', 'offline-company', 'offline-user', 'PHOTONPAY', 'offline-provider-card'],
        'pan' => '4111111111111111', 'cvv' => '123', 'expiry' => '12/30',
    ]));
    $details = app(CardSensitiveDetails::class)->read($card);
    expect($details->displayPan)->toBe('4111111111111111')->and($details->expiry)->toBe('12/30');
    Http::assertNothingSent();
});

it('refuses mismatched saved identities even offline', function () {
    $card = (new UserCard)->forceFill(['id' => 'different-card', 'last4' => '1111']);
    $card->sensitive_details_encrypted = Crypt::encryptString(json_encode(['identity' => ['wrong-card']]));
    expect(fn () => app(CardSensitiveDetails::class)->read($card))->toThrow(RuntimeException::class, 'Stored card identity mismatch.');
    Http::assertNothingSent();
});
