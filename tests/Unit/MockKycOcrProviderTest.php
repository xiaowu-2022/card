<?php

use App\Domain\Kyc\DTOs\KycOcrRequestDTO;
use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Kyc\Enums\KycOcrOutcome;
use App\Infrastructure\Providers\Kyc\MockKycOcrProvider;

it('normalizes mock OCR success behind the KYC provider contract', function (): void {
    $result = (new MockKycOcrProvider('SUCCESS'))->extractIdentityDocument(new KycOcrRequestDTO(KycDocumentType::NationalId, 'MY', 'front', 'back'));
    expect($result->outcome)->toBe(KycOcrOutcome::Success)
        ->and($result->candidateIdentityNumber)->toStartWith('MOCK-');
});

it('supports a deterministic failed OCR result without review state', function (): void {
    $result = (new MockKycOcrProvider('FAILED'))->extractIdentityDocument(new KycOcrRequestDTO(KycDocumentType::NationalId, 'MY', 'front', 'back'));
    expect($result->outcome)->toBe(KycOcrOutcome::Failed)
        ->and($result->candidateIdentityNumber)->toBeNull();
});
