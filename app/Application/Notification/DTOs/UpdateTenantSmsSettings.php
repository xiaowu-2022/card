<?php

namespace App\Application\Notification\DTOs;

final readonly class UpdateTenantSmsSettings
{
    public function __construct(
        public bool $enabled,
        #[\SensitiveParameter] public ?string $accessKeyId,
        #[\SensitiveParameter] public ?string $accessKeySecret,
        public string $signName,
        public string $verificationTemplateCode,
        public ?string $existingAccountTemplateCode,
        public int $resendIntervalSeconds,
        public int $codeTtlSeconds,
    ) {}
}
