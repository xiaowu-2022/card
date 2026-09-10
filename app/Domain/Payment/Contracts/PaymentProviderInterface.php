<?php

namespace App\Domain\Payment\Contracts;

use App\Domain\Payment\DTOs\NormalizedPaymentEvent;
use App\Domain\Payment\DTOs\PaymentInitiationRequest;
use App\Domain\Payment\DTOs\PaymentProviderResult;
use App\Domain\Payment\DTOs\VerifiedPaymentWebhook;
use Illuminate\Http\Request;

interface PaymentProviderInterface
{
    public function name(): string;

    public function available(): bool;

    /**
     * Repeating initiation with the same providerRequestId MUST be idempotent and
     * resolve the same external payment resource. A new semantic request id is forbidden.
     */
    public function initiatePayment(PaymentInitiationRequest $request): PaymentProviderResult;

    public function queryPayment(string $providerRequestId): PaymentProviderResult;

    public function verifyWebhook(Request $request, string $rawBody): VerifiedPaymentWebhook;

    public function normalizeWebhook(VerifiedPaymentWebhook $webhook): NormalizedPaymentEvent;
}
