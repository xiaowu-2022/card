<?php

namespace App\Domain\Kyc\Contracts;

use App\Domain\Kyc\DTOs\KycOcrRequestDTO;
use App\Domain\Kyc\DTOs\KycOcrResultDTO;

interface KycOcrProviderInterface
{
    public function name(): string;

    public function extractIdentityDocument(KycOcrRequestDTO $request): KycOcrResultDTO;
}
