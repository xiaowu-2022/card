<?php

namespace App\Application\Payment;

use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Payment\Contracts\PaymentProviderInterface;
use App\Domain\Payment\DTOs\NormalizedPaymentEvent;
use App\Domain\Payment\Enums\PaymentEventProcessingStatus;
use App\Domain\Payment\Models\PaymentProviderEvent;
use App\Domain\Payment\Models\PaymentProviderTransaction;
use App\Jobs\ProcessPaymentProviderEventJob;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final readonly class PersistPaymentProviderEventAction
{
    public function __construct(private PaymentProviderInterface $provider) {}

    public function execute(string $providerName, Request $request): PaymentProviderEvent
    {
        if ($providerName !== $this->provider->name() || ! $this->provider->available()) {
            throw new DomainException('PAYMENT_PROVIDER_UNAVAILABLE', 'Payment provider is unavailable.', 404);
        }
        $rawBody = $request->getContent();
        if (strlen($rawBody) > (int) config('payment.webhook_max_bytes')) {
            throw new DomainException('PAYMENT_WEBHOOK_TOO_LARGE', 'Webhook payload is too large.', 413);
        }
        $event = $this->provider->normalizeWebhook($this->provider->verifyWebhook($request, $rawBody));
        $event = $this->validateFinancialFields($event);
        $transaction = $this->mappedTransaction($providerName, $event);
        if (! $transaction) {
            Log::warning('Verified payment event did not map to an internal transaction.', [
                'provider' => $providerName,
                'event_key_hash' => hash('sha256', $event->eventKey),
                'payload_digest' => $event->payloadDigest,
            ]);
            throw new DomainException('PAYMENT_RESOURCE_UNKNOWN', 'Payment resource is unknown.', 202);
        }
        $existing = PaymentProviderEvent::query()->where('provider', $providerName)->where('provider_event_key', $event->eventKey)->first();
        if ($existing) {
            $stored = $this->resolveDuplicate($existing, $event);
            if ($stored->processing_status === PaymentEventProcessingStatus::Pending) {
                ProcessPaymentProviderEventJob::dispatch($stored->tenant_id, $stored->id);
            }

            return $stored;
        }

        try {
            $stored = DB::transaction(function () use ($transaction, $providerName, $event): PaymentProviderEvent {
                $now = now();
                DB::table('payment_provider_events')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $transaction->tenant_id,
                    'payment_provider_transaction_id' => $transaction->id, 'provider' => $providerName,
                    'provider_event_key' => $event->eventKey, 'event_type' => $event->eventType,
                    'provider_transaction_id' => $event->providerTransactionId, 'provider_request_id' => $event->providerRequestId,
                    'normalized_status' => $event->status?->value, 'asset_code' => $event->assetCode, 'amount' => $event->amount,
                    'payload_digest' => $event->payloadDigest, 'processing_status' => PaymentEventProcessingStatus::Pending->value,
                    'received_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);

                return PaymentProviderEvent::query()->where('provider', $providerName)->where('provider_event_key', $event->eventKey)->firstOrFail();
            }, 3);
        } catch (QueryException $exception) {
            $existing = PaymentProviderEvent::query()->where('provider', $providerName)->where('provider_event_key', $event->eventKey)->first();
            if (! $existing) {
                throw $exception;
            }
            $stored = $this->resolveDuplicate($existing, $event);
        }

        if ($stored->processing_status === PaymentEventProcessingStatus::Pending) {
            ProcessPaymentProviderEventJob::dispatch($stored->tenant_id, $stored->id);
        }

        return $stored;
    }

    private function mappedTransaction(string $providerName, NormalizedPaymentEvent $event): ?PaymentProviderTransaction
    {
        if ($event->providerRequestId === null && $event->providerTransactionId === null) {
            return null;
        }
        $byRequestMatches = $event->providerRequestId === null ? collect() : PaymentProviderTransaction::query()
            ->where('provider', $providerName)->where('provider_request_id', $event->providerRequestId)->limit(2)->get();
        $byTransactionMatches = $event->providerTransactionId === null ? collect() : PaymentProviderTransaction::query()
            ->where('provider', $providerName)->where('provider_transaction_id', $event->providerTransactionId)->limit(2)->get();
        if ($byRequestMatches->count() > 1 || $byTransactionMatches->count() > 1) {
            throw new DomainException('PAYMENT_MAPPING_AMBIGUOUS', 'Payment resource mapping is invalid.', 409);
        }
        $byRequest = $byRequestMatches->first();
        $byTransaction = $byTransactionMatches->first();
        if ($byRequest && $byTransaction && $byRequest->id !== $byTransaction->id) {
            if ($byRequest->tenant_id !== $byTransaction->tenant_id) {
                throw new DomainException('PAYMENT_MAPPING_AMBIGUOUS', 'Payment resource mapping is invalid.', 409);
            }

            return $byRequest;
        }

        return $byRequest ?? $byTransaction;
    }

    private function resolveDuplicate(PaymentProviderEvent $existing, NormalizedPaymentEvent $event): PaymentProviderEvent
    {
        if (! hash_equals($existing->payload_digest, $event->payloadDigest)) {
            DB::table('payment_provider_events')->where('id', $existing->id)->update([
                'processing_status' => PaymentEventProcessingStatus::RequiresReview->value,
                'updated_at' => now(),
            ]);
            throw new DomainException('PAYMENT_EVENT_CONFLICT', 'A conflicting provider event requires review.', 409);
        }

        return $existing->fresh();
    }

    private function validateFinancialFields(NormalizedPaymentEvent $event): NormalizedPaymentEvent
    {
        if (($event->amount === null) !== ($event->assetCode === null)) {
            throw new DomainException('PAYMENT_WEBHOOK_INVALID', 'Webhook financial fields are invalid.');
        }
        if ($event->amount === null) {
            return $event;
        }
        try {
            $money = Money::of($event->amount, $event->assetCode);
        } catch (Throwable) {
            throw new DomainException('PAYMENT_WEBHOOK_INVALID', 'Webhook financial fields are invalid.');
        }
        if (! $money->isPositive()) {
            throw new DomainException('PAYMENT_WEBHOOK_INVALID', 'Webhook financial fields are invalid.');
        }

        return new NormalizedPaymentEvent(
            $event->eventKey, $event->eventType, $event->providerTransactionId, $event->providerRequestId,
            $event->status, $money->amount(), $money->assetCode, $event->payloadDigest,
        );
    }
}
