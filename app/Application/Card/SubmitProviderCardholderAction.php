<?php

namespace App\Application\Card;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Card\Enums\ProviderCardholderStatus;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\CardProvider\DTOs\CardholderRequestDTO;
use App\Domain\CardProvider\DTOs\ProviderCardholderDTO;
use App\Domain\CardProvider\DTOs\ProviderIdentityDocumentDTO;
use App\Domain\CardProvider\Enums\ProviderCardholderReviewStatus;
use App\Domain\CardProvider\Exceptions\ProviderRejectedException;
use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Kyc\Enums\KycUserStatus;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\IdentityNumberProtector;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserProfile;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final readonly class SubmitProviderCardholderAction
{
    public function __construct(
        private CardProviderInterface $provider,
        private KycStatusService $kycStatus,
        private IdentityNumberProtector $identities,
        private AuditLogger $audit,
    ) {}

    /** @param array<string,mixed> $data */
    public function execute(string $tenantId, string $userId, array $data, ?string $requestId = null): ProviderCardholder
    {
        if (! $this->provider->available() || $this->provider->name() !== 'PHOTONPAY') {
            throw new DomainException('CARD_PROVIDER_UNAVAILABLE', 'Card setup is currently unavailable.', 503);
        }

        /** @var array{cardholder:ProviderCardholder,update:bool} $prepared */
        $prepared = DB::transaction(function () use ($tenantId, $userId, $data): array {
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail();
            if ($tenant->status !== TenantStatus::Active || $user->status !== UserStatus::Active) {
                throw new DomainException('CARD_SETUP_UNAVAILABLE', 'Card setup requires an active account.', 403);
            }
            if ($this->kycStatus->forUser($tenantId, $userId) !== KycUserStatus::Approved) {
                throw new DomainException('KYC_NOT_APPROVED', 'Approved identity verification is required before Card setup.', 403);
            }
            $identity = IdentityRecord::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->first();
            if (! $identity) {
                throw new DomainException('VERIFIED_IDENTITY_UNAVAILABLE', 'Verified identity information is unavailable.', 409);
            }
            $profile = UserProfile::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->lockForUpdate()->firstOrFail();
            $profile->forceFill([
                'legal_first_name' => trim((string) $data['legal_first_name']),
                'legal_last_name' => trim((string) $data['legal_last_name']),
                'date_of_birth' => $data['date_of_birth'],
                'nationality_country_code' => strtoupper((string) $data['nationality_country_code']),
                'residential_address' => trim((string) $data['residential_address']),
                'residential_city' => trim((string) $data['residential_city']),
                'residential_state' => trim((string) $data['residential_state']),
                'residential_country_code' => strtoupper((string) $data['residential_country_code']),
                'residential_postal_code' => trim((string) $data['residential_postal_code']),
            ])->save();

            $cardholder = ProviderCardholder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
                ->where('provider', 'PHOTONPAY')->lockForUpdate()->first();
            $update = $cardholder?->status === ProviderCardholderStatus::ActionRequired && $cardholder->provider_cardholder_id !== null;
            if ($cardholder && ! $update) {
                throw new DomainException(
                    $cardholder->status === ProviderCardholderStatus::Unknown ? 'CARDHOLDER_OUTCOME_UNKNOWN' : 'CARDHOLDER_ALREADY_SUBMITTED',
                    $cardholder->status === ProviderCardholderStatus::Unknown
                        ? 'Card setup status is unknown. It must be safely reconciled before another submission.'
                        : 'Card setup has already been submitted.',
                    409,
                );
            }
            $cardholder ??= new ProviderCardholder;
            $cardholder->forceFill([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'provider' => 'PHOTONPAY',
                'status' => ProviderCardholderStatus::Submitting,
                'safe_reason' => null,
            ])->save();

            return ['cardholder' => $cardholder, 'update' => $update];
        }, 3);

        try {
            $request = $this->providerRequest($tenantId, $userId, $prepared['update'] ? $prepared['cardholder']->provider_cardholder_id : null);
            $result = $prepared['update'] ? $this->provider->updateCardholder($request) : $this->provider->createCardholder($request);

            return $this->applyResult($tenantId, $userId, $prepared['cardholder']->id, $result, $requestId, true);
        } catch (ProviderRejectedException) {
            return $this->applyRejected($tenantId, $userId, $prepared['cardholder']->id, $requestId);
        } catch (Throwable) {
            return $this->applyUnknown($tenantId, $userId, $prepared['cardholder']->id, $requestId);
        }
    }

    private function providerRequest(string $tenantId, string $userId, ?string $providerCardholderId): CardholderRequestDTO
    {
        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->with('profile')->firstOrFail();
        $identity = IdentityRecord::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->firstOrFail();
        $application = KycApplication::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->whereKey($identity->source_kyc_application_id)->firstOrFail();
        $documentType = match ($identity->document_type) {
            KycDocumentType::NationalId => 'id_card',
        };
        $disk = Storage::disk((string) config('kyc.document_disk'));
        if (! $disk->exists($application->front_object_key) || ! $disk->exists($application->back_object_key)) {
            throw new DomainException('KYC_DOCUMENT_UNAVAILABLE', 'Verified identity documents are unavailable.', 409);
        }
        $front = $disk->get($application->front_object_key);
        $back = $disk->get($application->back_object_key);
        $profile = $user->profile;
        if (! $profile || ! $user->email) {
            throw new DomainException('CARDHOLDER_CONTACT_UNAVAILABLE', 'A verified email is required for this Demo Card setup.', 409);
        }

        return new CardholderRequestDTO(
            $profile->legal_first_name,
            $profile->legal_last_name,
            $profile->date_of_birth->format('Y-m-d'),
            $user->email,
            null,
            null,
            $profile->nationality_country_code,
            $profile->residential_address,
            $profile->residential_city,
            $profile->residential_state,
            $profile->residential_country_code,
            $profile->residential_postal_code,
            new ProviderIdentityDocumentDTO(
                $documentType,
                $identity->document_country,
                $this->identities->decrypt($identity->identity_number_encrypted),
                $front,
                $this->imageMime($front),
                $back,
                $this->imageMime($back),
            ),
            $providerCardholderId,
        );
    }

    private function imageMime(string $contents): string
    {
        if (str_starts_with($contents, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($contents, "\xff\xd8\xff")) {
            return 'image/jpeg';
        }

        throw new DomainException('KYC_DOCUMENT_FORMAT_UNSUPPORTED', 'The verified identity document format is not supported by the Card provider.', 409);
    }

    private function applyResult(string $tenantId, string $userId, string $id, ProviderCardholderDTO $result, ?string $requestId, bool $submitted): ProviderCardholder
    {
        return DB::transaction(function () use ($tenantId, $userId, $id, $result, $requestId, $submitted): ProviderCardholder {
            $cardholder = ProviderCardholder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($id)->lockForUpdate()->firstOrFail();
            $before = $cardholder->status->value;
            $changes = [
                'status' => $this->internalStatus($result->status),
                'provider_status' => $result->providerStatus,
                'provider_review_status' => $result->providerReviewStatus,
                'safe_reason' => $result->safeReason,
                'submitted_at' => $submitted ? ($cardholder->submitted_at ?? now()) : $cardholder->submitted_at,
                'synced_at' => now(),
            ];
            if ($result->providerCardholderId !== null) {
                $changes['provider_cardholder_id'] = $result->providerCardholderId;
            }
            $cardholder->forceFill($changes)->save();
            $this->audit->record($tenantId, 'USER', $userId, $submitted ? 'PHOTONPAY_CARDHOLDER_SUBMITTED' : 'PHOTONPAY_CARDHOLDER_STATUS_SYNCED', 'provider_cardholder', $id, ['status' => $before], ['status' => $cardholder->status->value], $requestId);

            return $cardholder;
        }, 3);
    }

    private function applyRejected(string $tenantId, string $userId, string $id, ?string $requestId): ProviderCardholder
    {
        return $this->applyResult($tenantId, $userId, $id, new ProviderCardholderDTO(null, ProviderCardholderReviewStatus::Rejected, 'rejected', 'rejected', 'Card setup was not accepted.'), $requestId, true);
    }

    private function applyUnknown(string $tenantId, string $userId, string $id, ?string $requestId): ProviderCardholder
    {
        return DB::transaction(function () use ($tenantId, $userId, $id, $requestId): ProviderCardholder {
            $cardholder = ProviderCardholder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($id)->lockForUpdate()->firstOrFail();
            $cardholder->forceFill(['status' => ProviderCardholderStatus::Unknown, 'safe_reason' => 'Card setup status could not be confirmed.', 'submitted_at' => now()])->save();
            $this->audit->record($tenantId, 'USER', $userId, 'PHOTONPAY_CARDHOLDER_SUBMITTED', 'provider_cardholder', $id, ['status' => ProviderCardholderStatus::Submitting->value], ['status' => ProviderCardholderStatus::Unknown->value], $requestId);

            return $cardholder;
        }, 3);
    }

    private function internalStatus(ProviderCardholderReviewStatus $status): ProviderCardholderStatus
    {
        return ProviderCardholderStatus::from($status->value);
    }
}
