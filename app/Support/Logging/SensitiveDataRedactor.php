<?php

namespace App\Support\Logging;

final class SensitiveDataRedactor
{
    private const SENSITIVE_KEY_PATTERN = '/^(?:code|code_hash|verification_code|verification_code_hash|identity_number_encrypted|identity_hash|ocr_result|ocr_result_encrypted|document_url|signed_url|front_object_key|back_object_key|(?:.*_)?(?:password(?:_confirmation)?|otp|identity_number|identity_document|api_key|secret|token|pan|cvv|authorization|cookie|set_cookie))$/';

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function redact(array $data): array
    {
        $redacted = [];

        foreach ($data as $key => $value) {
            $normalized = $this->normalizeKey((string) $key);
            $redacted[$key] = preg_match(self::SENSITIVE_KEY_PATTERN, $normalized) === 1
                ? '[REDACTED]'
                : $this->redactValue($value);
        }

        return $redacted;
    }

    public function redactString(string $value): string
    {
        return preg_replace('/\bBearer\s+[^\s,;]+/i', 'Bearer [REDACTED]', $value) ?? $value;
    }

    private function normalizeKey(string $key): string
    {
        $snakeCase = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key) ?? $key;

        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($snakeCase)) ?? $snakeCase, '_');
    }

    private function redactValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->redact($value);
        }

        return is_string($value) ? $this->redactString($value) : $value;
    }
}
