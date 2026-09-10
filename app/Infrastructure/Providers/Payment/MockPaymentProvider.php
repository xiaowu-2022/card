<?php

namespace App\Infrastructure\Providers\Payment;

use App\Domain\Payment\Contracts\PaymentProviderInterface;
use App\Domain\Payment\DTOs\NormalizedPaymentEvent;
use App\Domain\Payment\DTOs\PaymentInitiationRequest;
use App\Domain\Payment\DTOs\PaymentProviderResult;
use App\Domain\Payment\DTOs\VerifiedPaymentWebhook;
use App\Domain\Payment\Enums\MockPaymentMode;
use App\Domain\Payment\Enums\PaymentProviderTransactionStatus;
use App\Domain\Payment\Exceptions\PaymentProviderTimeoutException;
use App\Support\Errors\DomainException;
use Illuminate\Http\Request;
use JsonException;

final class MockPaymentProvider implements PaymentProviderInterface
{
    /** @var array<string, PaymentProviderResult> */
    private static array $payments = [];

    public function __construct(private readonly MockPaymentMode $mode, private readonly string $webhookSecret) {}

    public function name(): string
    {
        return 'mock';
    }

    public function available(): bool
    {
        return true;
    }

    public function initiatePayment(PaymentInitiationRequest $request): PaymentProviderResult
    {
        if (! $this->available()) {
            throw new DomainException('PAYMENT_PROVIDER_UNAVAILABLE', 'Top-up is currently unavailable.', 503);
        }
        if ($this->mode === MockPaymentMode::Timeout) {
            throw new PaymentProviderTimeoutException('Mock payment response timed out.');
        }

        $origin = preg_replace('#(/wallet/top-ups/.*)$#', '', $request->returnUrl) ?: 'http://localhost';
        $result = new PaymentProviderResult(
            $this->status(),
            $request->providerRequestId,
            'mock_tx_'.substr(hash('sha256', $request->providerRequestId), 0, 24),
            $request->amount,
            $request->assetCode,
            $this->mode === MockPaymentMode::Failed ? null : rtrim($origin, '/').'/__mock/payments/'.$request->providerRequestId,
        );
        self::$payments[$request->providerRequestId] = $result;

        return $result;
    }

    public function queryPayment(string $providerRequestId): PaymentProviderResult
    {
        if ($this->mode === MockPaymentMode::Timeout) {
            throw new PaymentProviderTimeoutException('Mock payment query timed out.');
        }

        $stored = self::$payments[$providerRequestId] ?? null;

        return new PaymentProviderResult(
            $this->status(),
            $providerRequestId,
            $stored?->providerTransactionId,
            $stored?->amount ?? '0.00000000',
            $stored?->assetCode ?? 'USD',
        );
    }

    public function verifyWebhook(Request $request, string $rawBody): VerifiedPaymentWebhook
    {
        $provided = (string) $request->header('X-Mock-Signature', '');
        $expected = hash_hmac('sha256', $rawBody, $this->webhookSecret);
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            throw new DomainException('PAYMENT_SIGNATURE_INVALID', 'Webhook signature is invalid.', 401);
        }

        return new VerifiedPaymentWebhook($rawBody);
    }

    public function normalizeWebhook(VerifiedPaymentWebhook $webhook): NormalizedPaymentEvent
    {
        try {
            $data = json_decode($webhook->rawBody, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainException('PAYMENT_WEBHOOK_INVALID', 'Webhook payload is invalid.');
        }
        if (! is_array($data) || ! is_string($data['event_id'] ?? null) || strlen($data['event_id']) > 180) {
            throw new DomainException('PAYMENT_WEBHOOK_INVALID', 'Webhook event identity is invalid.');
        }
        $status = isset($data['status']) ? PaymentProviderTransactionStatus::tryFrom((string) $data['status']) : null;

        return new NormalizedPaymentEvent(
            $data['event_id'],
            substr((string) ($data['event_type'] ?? 'PAYMENT_UPDATED'), 0, 80),
            isset($data['provider_transaction_id']) ? substr((string) $data['provider_transaction_id'], 0, 180) : null,
            isset($data['provider_request_id']) ? substr((string) $data['provider_request_id'], 0, 180) : null,
            $status,
            isset($data['amount']) && is_string($data['amount']) ? $data['amount'] : null,
            isset($data['asset']) && is_string($data['asset']) ? strtoupper($data['asset']) : null,
            hash('sha256', $webhook->rawBody),
        );
    }

    private function status(): PaymentProviderTransactionStatus
    {
        return match ($this->mode) {
            MockPaymentMode::Succeeded => PaymentProviderTransactionStatus::Succeeded,
            MockPaymentMode::Failed => PaymentProviderTransactionStatus::Failed,
            MockPaymentMode::Unknown, MockPaymentMode::Timeout => PaymentProviderTransactionStatus::Unknown,
            MockPaymentMode::Pending => PaymentProviderTransactionStatus::Pending,
        };
    }
}
