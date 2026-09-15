<?php

namespace App\Application\Card;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Card\Enums\ProviderCardholderStatus;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class SyncProviderCardholderAction
{
    public function __construct(private CardProviderInterface $provider, private AuditLogger $audit) {}

    public function execute(string $tenantId, string $userId, string $applicationId, ?string $requestId = null): ProviderCardholder
    {
        $snapshot = ProviderCardholder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->whereKey($applicationId)->where('provider', 'PHOTONPAY')->firstOrFail();
        if ($snapshot->card_product_id !== null) {
            LiveCardReferenceGuard::forProduct(CardProduct::findOrFail($snapshot->card_product_id), $snapshot->provider_cardholder_id);
        } else {
            LiveCardReferenceGuard::check($snapshot->provider_cardholder_id);
        }
        $provider = $snapshot->card_product_id === null ? $this->provider : app(CardProductProviderRouter::class)->forProduct(CardProduct::findOrFail($snapshot->card_product_id));
        if (! $provider->available() || $snapshot->provider_cardholder_id === null || $snapshot->status === ProviderCardholderStatus::Submitting) {
            throw new DomainException('CARDHOLDER_SYNC_UNAVAILABLE', 'Card setup status cannot be refreshed automatically.', 409);
        }
        try {
            $result = $provider->getCardholder($snapshot->provider_cardholder_id);
        } catch (Throwable) {
            return DB::transaction(function () use ($tenantId, $userId, $snapshot): ProviderCardholder {
                $cardholder = ProviderCardholder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
                if ($cardholder->submission_version !== $snapshot->submission_version || $cardholder->status !== $snapshot->status) {
                    return $cardholder;
                }
                if ($cardholder->status !== ProviderCardholderStatus::Ready) {
                    $cardholder->forceFill(['status' => ProviderCardholderStatus::Unknown, 'safe_reason' => 'Card setup status could not be confirmed.'])->save();
                }

                return $cardholder;
            }, 3);
        }

        return DB::transaction(function () use ($tenantId, $userId, $snapshot, $result, $requestId): ProviderCardholder {
            $cardholder = ProviderCardholder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            if ($cardholder->submission_version !== $snapshot->submission_version || $cardholder->status !== $snapshot->status
                || $result->providerCardholderId !== $snapshot->provider_cardholder_id
                || ($cardholder->status === ProviderCardholderStatus::Ready && in_array($result->status->value, ['PENDING', 'UNKNOWN', 'SUBMITTING'], true))) {
                return $cardholder;
            }
            $before = $cardholder->status->value;
            // A holder's old READY status does not prove a submitted material/document revision arrived.
            // Only an explicit successful update response confirms that revision; status-only recovery fails closed.
            if ($snapshot->submission_version > 1 && $snapshot->status !== ProviderCardholderStatus::Ready
                && $result->status->value === 'READY') {
                return $cardholder;
            }
            $cardholder->forceFill([
                'status' => ProviderCardholderStatus::from($result->status->value),
                'provider_status' => $result->providerStatus,
                'provider_review_status' => $result->providerReviewStatus,
                'safe_reason' => $result->safeReason,
                'synced_at' => now(),
            ])->save();
            $this->audit->record($tenantId, 'USER', $userId, 'PHOTONPAY_CARDHOLDER_STATUS_SYNCED', 'provider_cardholder', $cardholder->id, ['status' => $before], ['status' => $cardholder->status->value], $requestId);

            return $cardholder;
        }, 3);
    }
}
