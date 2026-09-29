<?php

namespace App\Application\Media;

/** Server-created, scoped and verified bytes. Never serialize this value into a response. */
final readonly class VerifiedDirectImage
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $userId,
        public string $purpose,
        public string $field,
        #[\SensitiveParameter] private string $contents,
        private string $mime,
    ) {}

    public function getContent(): string
    {
        return $this->contents;
    }

    public function get(): string
    {
        return $this->contents;
    }

    public function getSize(): int
    {
        return strlen($this->contents);
    }

    public function getMimeType(): string
    {
        return $this->mime;
    }

    public function isValid(): bool
    {
        return true;
    }
}
