<?php

namespace App\Application\Kyc;

use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\DTOs\KycOcrRequestDTO;
use App\Domain\Kyc\DTOs\KycOcrResultDTO;
use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Kyc\Enums\KycOcrOutcome;
use App\Domain\Kyc\Enums\KycOcrSide;
use App\Domain\Kyc\Services\DocumentValidity;
use App\Support\Errors\DomainException;

final class RecognizeKycBackValidity
{
    public function execute(#[\SensitiveParameter] string $backUrl): KycOcrResultDTO
    {
        try {
            $result = app(KycOcrProviderInterface::class)->extractIdentityDocument(new KycOcrRequestDTO(
                KycDocumentType::NationalId, 'CN', '', $backUrl, KycOcrSide::Back,
            ));
        } catch (\Throwable) {
            throw new DomainException('KYC_OCR_UNAVAILABLE', 'Document recognition is temporarily unavailable. Please try again later.', 503);
        }
        if ($result->outcome !== KycOcrOutcome::Success || $result->validity === null) {
            throw new DomainException('KYC_BACK_VALIDITY_UNRECOGNIZED', 'The identity card validity period could not be recognized. Please upload a clear photo of the back.');
        }
        app(DocumentValidity::class)->assertStructure($result->validity);

        return $result;
    }
}
