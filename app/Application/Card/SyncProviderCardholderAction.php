<?php

namespace App\Application\Card;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Card\Enums\ProviderCardholderStatus;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class SyncProviderCardholderAction
{
    public function __construct(private CardProviderInterface $provider, private AuditLogger $audit) {}

    public function execute(string $tenantId, string $userId, ?string $requestId = null): ProviderCardholder
    {
        $snapshot = ProviderCardholder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->where('provider', 'PHOTONPAY')->firstOrFail();
        if (! $this->provider->available() || $snapshot->provider_cardholder_id === null) {
            throw new DomainException('CARDHOLDER_SYNC_UNAVAILABLE', 'Card setup status cannot be refreshed automatically.', 409);
        }
        try {
            $result = $this->provider->getCardholder($snapshot->provider_cardholder_id);
        } catch (Throwable) {
            return DB::transaction(function () use ($tenantId, $userId, $snapshot): ProviderCardholder {
                $cardholder = ProviderCardholder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
                if ($cardholder->status !== ProviderCardholderStatus::Ready) {
                    $cardholder->forceFill(['status' => ProviderCardholderStatus::Unknown, 'safe_reason' => 'Card setup status could not be confirmed.'])->save();
                }

                return $cardholder;
            }, 3);
        }

        return DB::transaction(function () use ($tenantId, $userId, $snapshot, $result, $requestId): ProviderCardholder {
            $cardholder = ProviderCardholder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            $before = $cardholder->status->value;
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
