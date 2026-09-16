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
