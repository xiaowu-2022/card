<?php

use App\Domain\Payment\Contracts\PaymentProviderInterface;
use App\Domain\Payment\DTOs\PaymentInitiationRequest;
use App\Domain\Payment\Enums\MockPaymentMode;
use App\Domain\Payment\Enums\PaymentProviderTransactionStatus;
use App\Domain\Payment\Exceptions\PaymentProviderTimeoutException;
use App\Infrastructure\Providers\Payment\MockPaymentProvider;
use App\Support\Errors\DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

it('implements the payment provider contract with stable request semantics', function (): void {
    $provider = new MockPaymentProvider(MockPaymentMode::Succeeded, 'secret');
    $id = (string) Str::uuid();
    $result = $provider->initiatePayment(new PaymentInitiationRequest($id, $id, $id, '100.00000000', 'USD', 'http://a.localhost/return'));
    $query = $provider->queryPayment($id);

    expect($provider)->toBeInstanceOf(PaymentProviderInterface::class)
        ->and($result->status)->toBe(PaymentProviderTransactionStatus::Succeeded)
        ->and($query->providerRequestId)->toBe($id)
        ->and($query->amount)->toBe('100.00000000');
});

it('maps timeout to an unknown-reconcilable exception rather than failed', function (): void {
    $id = (string) Str::uuid();
    $provider = new MockPaymentProvider(MockPaymentMode::Timeout, 'secret');
    expect(fn () => $provider->initiatePayment(new PaymentInitiationRequest($id, $id, $id, '1.00000000', 'USD', 'http://a.localhost/return')))
        ->toThrow(PaymentProviderTimeoutException::class);
});

it('verifies signatures and normalizes only bounded safe payment fields', function (): void {
    $provider = new MockPaymentProvider(MockPaymentMode::Pending, 'secret');
    $body = json_encode(['event_id' => 'event-1', 'event_type' => 'PAYMENT_SUCCEEDED', 'provider_request_id' => (string) Str::uuid(), 'status' => 'SUCCEEDED', 'amount' => '10.00000000', 'asset' => 'USD'], JSON_THROW_ON_ERROR);
    $request = Request::create('/webhooks/payments/mock', 'POST', [], [], [], [], $body);
    expect(fn () => $provider->verifyWebhook($request, $body))->toThrow(DomainException::class);
    $request->headers->set('X-Mock-Signature', hash_hmac('sha256', $body, 'secret'));
    $normalized = $provider->normalizeWebhook($provider->verifyWebhook($request, $body));
    expect($normalized->eventKey)->toBe('event-1')->and($normalized->status)->toBe(PaymentProviderTransactionStatus::Succeeded);
});
