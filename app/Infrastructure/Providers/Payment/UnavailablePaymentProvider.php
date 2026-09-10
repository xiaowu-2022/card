<?php

namespace App\Infrastructure\Providers\Payment;

use App\Domain\Payment\Contracts\PaymentProviderInterface;
use App\Domain\Payment\DTOs\NormalizedPaymentEvent;
use App\Domain\Payment\DTOs\PaymentInitiationRequest;
use App\Domain\Payment\DTOs\PaymentProviderResult;
use App\Domain\Payment\DTOs\VerifiedPaymentWebhook;
use App\Support\Errors\DomainException;
use Illuminate\Http\Request;

final class UnavailablePaymentProvider implements PaymentProviderInterface
{
    public function name(): string
    {
        return 'unavailable';
    }

    public function available(): bool
    {
        return false;
    }

    public function initiatePayment(PaymentInitiationRequest $request): PaymentProviderResult
    {
        throw $this->exception();
    }

    public function queryPayment(string $providerRequestId): PaymentProviderResult
    {
        throw $this->exception();
    }

    public function verifyWebhook(Request $request, string $rawBody): VerifiedPaymentWebhook
    {
        throw $this->exception();
    }

    public function normalizeWebhook(VerifiedPaymentWebhook $webhook): NormalizedPaymentEvent
    {
        throw $this->exception();
    }

    private function exception(): DomainException
    {
        return new DomainException('PAYMENT_PROVIDER_UNAVAILABLE', 'Top-up is currently unavailable.', 503);
    }
}
