<?php

namespace App\Application\Card;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Card\Enums\ProviderCardholderStatus;
use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\Card\Services\CardholderGeography;
use App\Domain\Card\Services\CardholderMaterials;
use App\Domain\CardProduct\Enums\CardProductStatus;
use App\Domain\CardProduct\Enums\TenantCardProductStatus;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProduct\Models\TenantCardProductConfig;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\CardProvider\DTOs\CardholderRequestDTO;
use App\Domain\CardProvider\DTOs\ProviderCardholderDTO;
use App\Domain\CardProvider\DTOs\ProviderIdentityDocumentDTO;
use App\Domain\CardProvider\Enums\ProviderCardholderReviewStatus;
use App\Domain\CardProvider\Exceptions\ProviderRejectedException;
use App\Domain\Kyc\Enums\KycUserStatus;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\SecurityDeposit\Services\RefundCardPolicy;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Infrastructure\Providers\Card\LocalCardSimulation;
use App\Support\Errors\DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final readonly class SubmitProviderCardholderAction
{
    public function __construct(
        private CardProviderInterface $provider,
        private KycStatusService $kycStatus,
        private CardholderMaterials $materials,
        private CardholderGeography $geography,
        private AuditLogger $audit,
        private CardProductProviderRouter $router,
    ) {}

    /** @param array<string,mixed> $data */
    public function execute(string $tenantId, string $userId, #[\SensitiveParameter] array $data, ?string $requestId = null): ProviderCardholder
    {
        RefundCardPolicy::assertAllowed($tenantId, $userId);
        $this->assertOwner($tenantId, $userId);
        if (! Str::isUuid($data['request_id'] ?? '') || ! Str::isUuid($data['card_product_id'] ?? '')) {
            throw new DomainException('CARD_SETUP_INVALID', 'Enter valid cardholder details.');
        }
        $product = CardProduct::query()->whereKey($data['card_product_id'])->firstOrFail();
        $provider = $this->router->forProduct($product);
        $this->router->assertConfigured($product);
        LiveCardReferenceGuard::forProduct($product, $this->router->productReference($product));
        $fields = [];
        foreach (['legal_first_name', 'legal_last_name', 'date_of_birth', 'email', 'nationality_country_code',
            'residential_address', 'residential_city', 'residential_state', 'residential_country_code',
            'residential_postal_code', 'document_type', 'document_country'] as $key) {
            // Issuing country follows this cardholder's nationality, never a separate client value.
            // Preserve canonical field ordering so unchanged material keeps its request fingerprint.
            $value = $key === 'document_country' ? ($data['nationality_country_code'] ?? null) : ($data[$key] ?? null);
            if (! is_string($value) || trim($value) === '') {
                throw new DomainException('CARD_SETUP_INVALID', 'Enter valid cardholder details.');
            }
            $fields[$key] = trim($value);
        }
        // New forms omit the number. Keep optional legacy submissions replayable; never backfill from account KYC.
        $identityNumber = $data['identity_number'] ?? null;
        if ($identityNumber !== null && ! is_string($identityNumber)) {
            throw new DomainException('CARD_SETUP_INVALID', 'Enter valid cardholder details.');
        }
        $identityNumber = $identityNumber === null ? null : trim($identityNumber);
        if ($identityNumber !== null && $identityNumber !== '' && (mb_strlen($identityNumber) < 3 || mb_strlen($identityNumber) > 64)) {
            throw new DomainException('CARD_SETUP_INVALID', 'Enter valid cardholder details.');
        }
        $fields['identity_number'] = $identityNumber === '' ? null : $identityNumber;
        if (! in_array($fields['document_type'], ['id_card', 'passport', 'resident_permit'], true)) {
            throw new DomainException('CARD_SETUP_INVALID', 'Enter valid cardholder details.');
        }
        $this->geography->assertValid($fields);
        $fields += $this->geography->phone($data['mobile'] ?? null, $data['mobile_country_code'] ?? null);
        [$front, $frontMime] = $this->image($data['front'] ?? null);
        [$back, $backMime] = $fields['document_type'] === 'passport' && ! ($data['back'] ?? null)
            ? [null, null] : $this->image($data['back'] ?? null);
        $fingerprint = $this->materials->fingerprint($tenantId, $userId, $data['card_product_id'], json_encode([
            $fields, hash('sha256', $front), $back === null ? null : hash('sha256', $back),
        ], JSON_THROW_ON_ERROR));
        $disk = Storage::disk('private');
        $base = 'card-materials/'.$tenantId.'/'.$userId.'/'.Str::uuid();
        $keys = [];
        $committed = false;
        try {
            foreach (['front' => $front, 'back' => $back] as $side => $contents) {
                if ($contents === null) {
                    continue;
                }
                $key = $base.'/'.$side;
                if (! $disk->put($key, $this->materials->encrypt($contents))) {
                    throw new DomainException('CARD_MATERIALS_STORAGE_FAILED', 'The documents could not be stored. Please try again.', 503);
                }
                $keys[$side] = $key;
            }
            $encrypted = $this->materials->encrypt(json_encode(['fields' => $fields, 'documents' => $keys], JSON_THROW_ON_ERROR));
            $prepared = DB::transaction(function () use ($tenantId, $userId, $data, $fingerprint, $encrypted): array {
                Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
                User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail();
                RefundCardPolicy::assertAllowed($tenantId, $userId);
                $this->assertOwner($tenantId, $userId);
                $holder = ProviderCardholder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
                    ->where('request_id', $data['request_id'])->lockForUpdate()->first();
                if ($holder) {
                    LiveCardReferenceGuard::forProduct(CardProduct::findOrFail($holder->card_product_id), $holder->provider_cardholder_id);
                    if ($holder->card_product_id !== $data['card_product_id']) {
                        throw new DomainException('IDEMPOTENCY_CONFLICT', 'This request was already used with different details.', 409);
                    }
                    if (hash_equals($holder->request_hash, $fingerprint)) {
                        return ['holder' => $holder, 'send' => false, 'update' => false];
                    }
                    if (! in_array($holder->status, [ProviderCardholderStatus::Ready, ProviderCardholderStatus::ActionRequired], true) || ! $holder->provider_cardholder_id
                        || CardIssueOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('provider_cardholder_id', $holder->id)->exists()) {
                        throw new DomainException('IDEMPOTENCY_CONFLICT', 'This request was already used with different details.', 409);
                    }
                } else {
                    if (CardIssueOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereIn('status', ['PENDING', 'PROCESSING', 'UNKNOWN'])->exists()) {
                        throw new DomainException('CARD_SETUP_OUTSTANDING', 'Complete the current card application before starting another.', 409);
                    }
                    // Serialize new applications by owner. UNKNOWN cannot be bypassed with a new UUID.
                    $outstanding = ProviderCardholder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereNotNull('request_id')
                        ->when(! app()->environment('testing') && ! LocalCardSimulation::active(), fn ($query) => $query->withoutTestReferences())
                        ->whereNotIn('status', ['REJECTED', 'DISABLED'])
                        ->whereNotIn('id', CardIssueOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->select('provider_cardholder_id'))->exists();
                    if ($outstanding) {
                        throw new DomainException('CARD_SETUP_OUTSTANDING', 'Complete the current card application before starting another.', 409);
                    }
                    $config = TenantCardProductConfig::query()->where('tenant_id', $tenantId)->where('card_product_id', $data['card_product_id'])->first();
                    $product = CardProduct::query()->whereKey($data['card_product_id'])->lockForUpdate()->first();
                    if ($product) {
                        $this->router->assertConfigured($product);
                    }
                    if (! $config || $config->status !== TenantCardProductStatus::Active || ! $product || $product->status !== CardProductStatus::Active) {
                        throw new DomainException('CARD_PRODUCT_UNAVAILABLE', 'This Card product is not available.', 409);
                    }
                    if (CardIssueOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('card_product_id', $data['card_product_id'])->where('status', '!=', 'FAILED')->count() >= $config->max_cards_per_user) {
                        throw new DomainException('CARD_LIMIT_REACHED', 'You have reached the maximum number of Cards for this product.', 409);
                    }
                    $holder = new ProviderCardholder;
                    $holder->forceFill(['tenant_id' => $tenantId, 'user_id' => $userId, 'provider' => 'PHOTONPAY',
                        'request_id' => $data['request_id'], 'card_product_id' => $data['card_product_id']]);
                }
                $update = $holder->exists;
                $holder->forceFill([
                    'status' => ProviderCardholderStatus::Submitting, 'safe_reason' => null,
                    'request_hash' => $fingerprint, 'materials_encrypted' => $encrypted,
                    'submission_version' => ($holder->submission_version ?? 0) + 1,
                    'submitted_at' => now(), 'synced_at' => null,
                ])->save();

                return ['holder' => $holder, 'send' => true, 'update' => $update];
            }, 3);
            $committed = $prepared['send'];
        } finally {
            if (! $committed) {
                foreach ($keys as $key) {
                    try {
                        $disk->delete($key);
                    } catch (Throwable) {
                        Log::warning('Uncommitted card document cleanup failed.', ['tenant_id' => $tenantId]);
                    }
                }
            }
        }
        $holder = $prepared['holder'];
        if (! $prepared['send']) {
            return $holder;
        }
        // Only this card's freshly submitted materials are sent. Never read account identity documents.
        $request = new CardholderRequestDTO(
            $fields['legal_first_name'], $fields['legal_last_name'], $fields['date_of_birth'], $fields['email'], $fields['mobile'], $fields['mobile_prefix'],
            $fields['nationality_country_code'], $fields['residential_address'], $fields['residential_city'],
            $fields['residential_state'], $fields['residential_country_code'], $fields['residential_postal_code'],
            new ProviderIdentityDocumentDTO($fields['document_type'], $fields['document_country'], $fields['identity_number'], $front, $frontMime, $back, $backMime),
            $prepared['update'] ? $holder->provider_cardholder_id : null,
        );
        try {
            $result = $prepared['update'] ? $provider->updateCardholder($request) : $provider->createCardholder($request);

            return $this->applyResult($holder, $result, $requestId);
        } catch (ProviderRejectedException) {
            return $this->applyResult($holder, new ProviderCardholderDTO(null, ProviderCardholderReviewStatus::Rejected, 'rejected', 'rejected', 'Card setup was not accepted.'), $requestId);
        } catch (Throwable) {
            return $this->applyResult($holder, new ProviderCardholderDTO(null, ProviderCardholderReviewStatus::Unknown, null, null, 'Card setup status could not be confirmed.'), $requestId);
        }
    }

    private function assertOwner(string $tenantId, string $userId): void
    {
        $tenant = Tenant::query()->whereKey($tenantId)->firstOrFail();
        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
        if ($tenant->status !== TenantStatus::Active || $user->status !== UserStatus::Active) {
            throw new DomainException('CARD_SETUP_UNAVAILABLE', 'Card setup requires an active account.', 403);
        }
        if ($this->kycStatus->forUser($tenantId, $userId) !== KycUserStatus::Approved) {
            throw new DomainException('KYC_NOT_APPROVED', 'Approved identity verification is required before Card setup.', 403);
        }
    }

    /** @return array{string,string} */
    private function image(mixed $file): array
    {
        if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getSize() > 6 * 1024 * 1024
            || ! in_array($file->getMimeType(), ['image/jpeg', 'image/png'], true)) {
            throw new DomainException('CARD_DOCUMENT_INVALID', 'Upload a PNG or JPEG document of at most 6 MB.');
        }

        return [$file->getContent(), $file->getMimeType()];
    }

    private function applyResult(ProviderCardholder $snapshot, ProviderCardholderDTO $result, ?string $requestId): ProviderCardholder
    {
        if ($result->status === ProviderCardholderReviewStatus::Ready && trim($result->providerCardholderId ?? '') === '') {
            $result = new ProviderCardholderDTO(null, ProviderCardholderReviewStatus::Unknown, safeReason: 'Card setup status could not be confirmed.');
        }

        return DB::transaction(function () use ($snapshot, $result, $requestId): ProviderCardholder {
            $holder = ProviderCardholder::query()->where('tenant_id', $snapshot->tenant_id)->where('user_id', $snapshot->user_id)->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            if ($holder->submission_version !== $snapshot->submission_version || $holder->status !== ProviderCardholderStatus::Submitting) {
                return $holder;
            }
            if ($holder->provider_cardholder_id !== null && $result->providerCardholderId !== null && ! hash_equals($holder->provider_cardholder_id, $result->providerCardholderId)) {
                throw new DomainException('CARDHOLDER_IDENTITY_MISMATCH', 'Card setup status could not be confirmed.', 409);
            }
            $holder->forceFill([
                'status' => ProviderCardholderStatus::from($result->status->value),
                'provider_cardholder_id' => $result->providerCardholderId ?? $holder->provider_cardholder_id,
                'provider_status' => $result->providerStatus, 'provider_review_status' => $result->providerReviewStatus,
                'safe_reason' => $result->safeReason, 'synced_at' => now(),
            ])->save();
            $this->audit->record($holder->tenant_id, 'USER', $holder->user_id, 'PHOTONPAY_CARDHOLDER_SUBMITTED', 'provider_cardholder', $holder->id,
                ['status' => 'SUBMITTING'], ['status' => $holder->status->value], $requestId);

            return $holder;
        }, 3);
    }
}
