<?php

namespace App\Domain\CardProvider\DTOs;

final readonly class ProviderIdentityDocumentDTO
{
    public function __construct(
        public string $type,
        public string $countryCode,
        public ?string $identityNumber,
        public string $frontContents,
        public string $frontMimeType,
        public ?string $backContents,
        public ?string $backMimeType,
    ) {}
}
