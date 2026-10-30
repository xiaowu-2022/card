<?php

namespace App\Application\Kyc;

use App\Application\Media\ImageStorage;
use App\Domain\Kyc\DTOs\KycDocumentValidityDTO;
use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\DocumentValidity;
use App\Domain\Kyc\Services\KycDataCipher;
use App\Support\Errors\DomainException;

final readonly class RequireCurrentBackValidity
{
    public function __construct(private KycDataCipher $cipher, private ImageStorage $images, private DocumentValidity $validity) {}

    public function execute(KycApplication $application): void
    {
        if ($application->document_type !== KycDocumentType::NationalId) {
            return;
        }

        try {
            $evidence = $application->back_ocr_result_encrypted
                ? json_decode($this->cipher->decrypt($application->back_ocr_result_encrypted), true, 4, JSON_THROW_ON_ERROR)
                : null;
        } catch (\Throwable) {
            throw $this->unrecognized();
        }

        $keys = ['version', 'document_type', 'tenant_id', 'user_id', 'back_object_key', 'sha256', 'provider', 'valid_from', 'valid_until', 'long_term'];
        $actualKeys = is_array($evidence) ? array_keys($evidence) : [];
        sort($keys);
        sort($actualKeys);
        if (! is_array($evidence) || $actualKeys !== $keys
            || $evidence['version'] !== 1 || $evidence['document_type'] !== KycDocumentType::NationalId->value
            || $evidence['tenant_id'] !== $application->tenant_id || $evidence['user_id'] !== $application->user_id
            || ! is_string($evidence['back_object_key']) || $evidence['back_object_key'] === ''
            || $evidence['back_object_key'] !== $application->back_object_key
            || ! is_string($evidence['sha256']) || preg_match('/^[a-f0-9]{64}$/D', $evidence['sha256']) !== 1
            || ! is_string($evidence['provider']) || trim($evidence['provider']) === '' || strlen($evidence['provider']) > 64
            || ! is_string($evidence['valid_from']) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $evidence['valid_from']) !== 1
            || ! is_bool($evidence['long_term'])
            || ($evidence['long_term'] ? $evidence['valid_until'] !== null
                : (! is_string($evidence['valid_until']) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $evidence['valid_until']) !== 1))) {
            throw $this->unrecognized();
        }

        // This is a database-only lookup. Approval never reads images or contacts storage/OCR.
        $image = $this->images->record((string) config('kyc.document_disk'), $application->back_object_key);
        if (! $image || $image->tenant_id !== $application->tenant_id || $image->purpose !== 'kyc'
            || $image->state !== 'ready' || ! is_string($image->sha256) || ! hash_equals($image->sha256, $evidence['sha256'])) {
            throw $this->unrecognized();
        }

        $this->validity->assertCurrent(new KycDocumentValidityDTO($evidence['valid_from'], $evidence['valid_until'], $evidence['long_term']));
    }

    private function unrecognized(): DomainException
    {
        return new DomainException('KYC_BACK_VALIDITY_UNRECOGNIZED', 'The identity card validity period could not be verified.');
    }
}
