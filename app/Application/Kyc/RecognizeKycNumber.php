<?php

namespace App\Application\Kyc;

use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\DTOs\KycOcrRequestDTO;
use App\Domain\Kyc\DTOs\KycOcrResultDTO;
use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Kyc\Enums\KycOcrFailureReason;
use App\Domain\Kyc\Enums\KycOcrOutcome;
use App\Domain\Kyc\Services\IdentityNumberNormalizer;
use App\Support\Errors\DomainException;

final class RecognizeKycNumber
{
    public function execute(KycDocumentType $type, string $country, #[\SensitiveParameter] string $frontUrl): KycOcrResultDTO
    {
        try {
            $ocr = app(KycOcrProviderInterface::class)->extractIdentityDocument(new KycOcrRequestDTO($type, $country, $frontUrl, ''));
        } catch (\Throwable) {
            throw new DomainException('KYC_OCR_UNAVAILABLE', 'Document recognition is temporarily unavailable. Please try again later.', 503);
        }
        if ($ocr->outcome !== KycOcrOutcome::Success || ! $ocr->candidateIdentityNumber) {
            throw new DomainException('KYC_OCR_MISMATCH', match ($ocr->failureReason) {
                KycOcrFailureReason::NumberFormat, KycOcrFailureReason::NumberChecksum => 'The recognized document number did not pass validation. Please retake the number area without glare.',
                default => 'The document number could not be recognized. Please upload a clear document image.',
            });
        }
        try {
            app(IdentityNumberNormalizer::class)->normalize($ocr->candidateIdentityNumber);
        } catch (DomainException) {
            throw new DomainException('KYC_OCR_MISMATCH', 'The document number could not be recognized. Please upload a clear document image.');
        }

        return $ocr;
    }
}
