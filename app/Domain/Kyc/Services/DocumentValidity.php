<?php

namespace App\Domain\Kyc\Services;

use App\Domain\Kyc\DTOs\KycDocumentValidityDTO;
use App\Support\Errors\DomainException;
use Carbon\CarbonImmutable;

final class DocumentValidity
{
    public function assertStructure(KycDocumentValidityDTO $validity): void
    {
        if (! $this->isDate($validity->validFrom)
            || ($validity->longTerm && $validity->validUntil !== null)
            || (! $validity->longTerm && ($validity->validUntil === null || ! $this->isDate($validity->validUntil)))
            || ($validity->validUntil !== null && $validity->validFrom > $validity->validUntil)) {
            throw new DomainException('KYC_BACK_VALIDITY_UNRECOGNIZED', 'The identity card validity period could not be recognized. Please upload a clear photo of the back.');
        }
    }

    public function assertCurrent(KycDocumentValidityDTO $validity): void
    {
        $this->assertStructure($validity);
        $today = CarbonImmutable::now('Asia/Shanghai')->toDateString();
        if ($validity->validFrom > $today) {
            throw new DomainException('KYC_DOCUMENT_NOT_YET_VALID', 'The identity card is not yet valid. Please upload a currently valid document.');
        }
        if ($validity->validUntil !== null && $validity->validUntil < $today) {
            throw new DomainException('KYC_DOCUMENT_EXPIRED', 'The identity card has expired. Please upload a valid document.');
        }
    }

    private function isDate(string $date): bool
    {
        return preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $date) === 1
            && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
    }
}
