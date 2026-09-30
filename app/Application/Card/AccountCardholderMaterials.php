<?php

namespace App\Application\Card;

use App\Application\Media\ImageStorage;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\KycDataCipher;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;

final class AccountCardholderMaterials
{
    public function resolve(string $tenantId, string $userId): array
    {
        $user = User::where('tenant_id', $tenantId)->findOrFail($userId);
        $identity = IdentityRecord::where('tenant_id', $tenantId)->where('user_id', $userId)->first();
        $application = $identity ? KycApplication::where('tenant_id', $tenantId)->where('user_id', $userId)
            ->where('review_status', 'APPROVED')->find($identity->source_kyc_application_id) : null;
        if (! $identity || ! $application || $application->document_country !== 'CN') {
            throw new DomainException('CARD_SETUP_INVALID', 'Approved identity verification is required before Card setup.', 422);
        }
        $birth = $user->profile?->date_of_birth?->format('Y-m-d');
        if ($identity->document_type->value === 'NATIONAL_ID') {
            $number = app(KycDataCipher::class)->decrypt($identity->identity_number_encrypted);
            if (preg_match('/^[0-9]{17}[0-9Xx]$/D', $number)) {
                $birth = substr($number, 6, 4).'-'.substr($number, 10, 2).'-'.substr($number, 12, 2);
            }
        }
        if (! is_string($birth) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $birth, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || $birth >= now()->format('Y-m-d')) {
            throw new DomainException('CARD_SETUP_INVALID', 'The birth date could not be obtained from your verified identity. Please contact support.', 422);
        }
        $keys = ['front' => $application->front_object_key];
        if ($identity->document_type->value !== 'PASSPORT') {
            $keys['back'] = $application->back_object_key;
        }
        $images = [];
        foreach ($keys as $side => $key) {
            if (! is_string($key) || $key === '') {
                throw new DomainException('CARD_DOCUMENT_INVALID', 'Approved identity verification is required before Card setup.', 422);
            }
            $storage = app(ImageStorage::class);
            $disk = (string) config('kyc.document_disk', 'private');
            try {
                $record = $storage->record($disk, $key);
                if ($record && $record->tenant_id !== $tenantId) {
                    throw new \RuntimeException;
                }
                $bytes = $storage->read($disk, $key);
                if ($record?->sha256 && ! hash_equals($record->sha256, hash('sha256', $bytes))) {
                    throw new \RuntimeException;
                }
            } catch (\Throwable) {
                throw new DomainException('IMAGE_STORAGE_UNAVAILABLE', 'Image storage is unavailable. Please try again.', 503);
            }
            $info = @getimagesizefromstring($bytes);
            if (strlen($bytes) > 6 * 1024 * 1024 || ! $info || ! in_array($info['mime'], ['image/jpeg', 'image/png'], true)
                || $info[0] > 12000 || $info[1] > 12000) {
                throw new DomainException('CARD_DOCUMENT_INVALID', 'Upload a PNG or JPEG document of at most 6 MB.', 422);
            }
            $images[$side] = [$bytes, $info['mime']];
        }

        return ['fields' => [
            'date_of_birth' => $birth, 'nationality_country_code' => 'CN',
            'residential_country_code' => 'CN', 'residential_state' => 'Fujian', 'residential_city' => 'Fuzhou',
            'residential_address' => '西湖花园3栋304室', 'residential_postal_code' => '351000',
            'document_type' => $identity->document_type->value === 'PASSPORT' ? 'passport' : 'id_card',
            'document_country' => 'CN',
        ], 'keys' => $keys, 'images' => $images];
    }
}
