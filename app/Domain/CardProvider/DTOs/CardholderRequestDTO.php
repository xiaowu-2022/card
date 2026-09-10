<?php

namespace App\Domain\CardProvider\DTOs;

final readonly class CardholderRequestDTO
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $dateOfBirth,
        public ?string $email,
        public ?string $mobile,
        public ?string $mobilePrefix,
        public string $nationalityCountryCode,
        public string $residentialAddress,
        public string $residentialCity,
        public string $residentialState,
        public string $residentialCountryCode,
        public string $residentialPostalCode,
        public ProviderIdentityDocumentDTO $identityDocument,
        public ?string $providerCardholderId = null,
    ) {}
}
