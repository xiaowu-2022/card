<?php

use App\Infrastructure\Providers\Card\PhotonPayNotificationVerifier;
use App\Support\Errors\DomainException;
use Tests\TestCase;

uses(TestCase::class);

it('verifies exact notification bytes using configured RSA keys and supported PEM newlines', function (int $bits, bool $escaped) {
    $key = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $public = openssl_pkey_get_details($key)['key'];
    config(['card-provider.photonpay.webhook_public_key' => $escaped ? str_replace("\n", '\\n', $public) : $public]);
    $body = '{"cardId":"PROVIDER-CARD-1","transactionId":"TX-1","amount":"20.00"}';
    openssl_sign($body, $signature, $key, OPENSSL_ALGO_MD5);
    $verifier = new PhotonPayNotificationVerifier;
    $facts = $verifier->verify($body, base64_encode($signature), 'issuing', 'auth');
    expect($facts['cardId'])->toBe('PROVIDER-CARD-1')->and($facts['transactionId'])->toBe('TX-1')
        ->and($facts)->not->toHaveKey('amount');
    // Whitespace is part of the signature: do not reserialize or normalize callbacks.
    expect(fn () => $verifier->verify($body.' ', base64_encode($signature), 'issuing', 'auth'))
        ->toThrow(DomainException::class, 'Invalid notification.');
})->with([[1024, false], [1024, true], [2048, false], [2048, true]]);

it('rejects absent malformed non-RSA and undersized verification keys', function (string $kind) {
    $value = '';
    if ($kind !== 'missing') {
        $key = openssl_pkey_new($kind === 'ec'
            ? ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']
            : ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $kind === 'small' ? 512 : 1024]);
        $value = openssl_pkey_get_details($key)['key'];
        if ($kind === 'malformed') {
            $value = str_replace('-----END', '\\-----END', $value);
        }
    }
    config(['card-provider.photonpay.webhook_public_key' => $value]);
    try {
        (new PhotonPayNotificationVerifier)->verify('{}', '', 'issuing', 'auth');
        $this->fail('Invalid verification configuration was accepted.');
    } catch (DomainException $error) {
        expect($error->errorCode)->toBe('CARD_WEBHOOK_UNAVAILABLE')->and($error->httpStatus)->toBe(503);
    }
})->with(['missing', 'malformed', 'ec', 'small']);

it('rejects unsigned callbacks and signatures made with a different RSA key', function (string $kind) {
    $trusted = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]);
    config(['card-provider.photonpay.webhook_public_key' => openssl_pkey_get_details($trusted)['key']]);
    $signature = '';
    if ($kind === 'other-key') {
        $other = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]);
        openssl_sign('{}', $bytes, $other, OPENSSL_ALGO_MD5);
        $signature = base64_encode($bytes);
    } elseif ($kind === 'invalid-base64') {
        $signature = '*not-base64*';
    }
    try {
        (new PhotonPayNotificationVerifier)->verify('{}', $signature, 'issuing', 'auth');
        $this->fail('Invalid notification signature was accepted.');
    } catch (DomainException $error) {
        expect($error->errorCode)->toBe('CARD_WEBHOOK_INVALID')->and($error->httpStatus)->toBe(401);
    }
})->with(['missing', 'other-key', 'invalid-base64']);

it('accepts empty optional identifiers without retaining notification contents', function (string $category) {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]);
    config(['card-provider.photonpay.webhook_public_key' => openssl_pkey_get_details($key)['key']]);
    $body = '{"cardId":"XR-CARD","transactionId":"IT-TRANSACTION","requestId":"","cardholderId":"","transactionAmount":10.00000001,"private":"discard"}';
    openssl_sign($body, $signature, $key, OPENSSL_ALGO_MD5);
    $facts = (new PhotonPayNotificationVerifier)->verify($body, base64_encode($signature), $category, 'auth');
    expect($facts['cardId'])->toBe('XR-CARD')->and($facts['transactionId'])->toBe('IT-TRANSACTION')
        ->and($facts['requestId'])->toBeNull()->and($facts['cardholderId'])->toBeNull()
        ->and($facts)->not->toHaveKeys(['transactionAmount', 'private']);
})->with(['issuing', 'issuing_settlement']);

it('reports bounded diagnostics for signed malformed bodies and identifiers', function (string $body, string $reason, ?string $field, ?string $state) {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]);
    config(['card-provider.photonpay.webhook_public_key' => openssl_pkey_get_details($key)['key']]);
    openssl_sign($body, $signature, $key, OPENSSL_ALGO_MD5);
    try {
        (new PhotonPayNotificationVerifier)->verify($body, base64_encode($signature), 'issuing', 'auth');
        $this->fail('Malformed callback was accepted.');
    } catch (DomainException $error) {
        expect($error->httpStatus)->toBe(422)->and($error->details['reason'])->toBe($reason)
            ->and($error->details['signature_verified'])->toBeTrue()
            ->and($error->details['body_bytes'])->toBe(strlen($body))
            ->and($error->details['field'] ?? null)->toBe($field)
            ->and($error->details['field_state'] ?? null)->toBe($state)
            ->and(json_encode($error->details))->not->toContain('private-marker');
    }
})->with([
    ['{"cardId":', 'invalid_json', null, null],
    ['[{"cardId":"private-marker"}]', 'object_required', null, null],
    ['{"requestId":[]}', 'identifier_invalid', 'requestId', 'wrong_type'],
    ['{"transactionId":false}', 'identifier_invalid', 'transactionId', 'wrong_type'],
    ['{"cardholderId":" "}', 'identifier_invalid', 'cardholderId', 'invalid_characters'],
    ['{"cardId":"private-marker/invalid"}', 'identifier_invalid', 'cardId', 'invalid_characters'],
    ['{"cardId":"private-marker\\n"}', 'identifier_invalid', 'cardId', 'invalid_characters'],
    [json_encode(['requestId' => str_repeat('x', 181)]), 'identifier_invalid', 'requestId', 'too_long'],
]);
