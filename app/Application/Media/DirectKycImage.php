<?php

namespace App\Application\Media;

/** A scoped upload URL, not server-verified image bytes. OCR is the content gate. */
final readonly class DirectKycImage
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $userId,
        public string $field,
        public string $url,
    ) {}
}
