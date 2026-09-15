<?php

namespace App\Support\Logging;

final class SensitiveDataRedactor
{
    private const SENSITIVE_KEY_PATTERN = '/^(?:access_key_id|phone_numbers|template_param|materials_encrypted|request_hash|front|back|cert_id|first_name|last_name|legal_first_name|legal_last_name|date_of_birth|residential_address|email|mobile|phone|code|code_hash|verification_code|verification_code_hash|identity_number_encrypted|identity_hash|ocr_result|ocr_result_encrypted|document_url|signed_url|front_object_key|back_object_key|portrait|reverse_side|withdrawal_address|address_ciphertext|address_hash|card_no|card_number|x_pd_token|x_pd_authorization|private_key|(?:.*_)?(?:password(?:_confirmation)?|otp|identity_number|identity_document|api_key|secret|token|pan|cvv|authorization|cookie|set_cookie))$/';

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function redact(array $data): array
    {
        $redacted = [];

        foreach ($data as $key => $value) {
            $normalized = $this->normalizeKey((string) $key);
            $redacted[$key] = preg_match(self::SENSITIVE_KEY_PATTERN, $normalized) === 1
                || in_array($normalized, ['photonpay_issuing_encrypted', 'photonpay_reporting_encrypted', 'address', 'reset_contact', 'password_reset_binding', 'ip_hash', 'trongrid_api_key_encrypted'], true)
                || in_array($normalized, ['new_contact', 'destination_hash', 'session_hash', 'credential_hash', 'contact_change_binding', 'support_message', 'support_image', 'image_object_key', 'image_hash', 'test_email', 'recipient_hash', 'smtp_transcript', 'smtp_debug', 'holder_changes_encrypted'], true)
                ? '[REDACTED]'
                : $this->redactValue($value);
        }

        return $redacted;
    }

    public function redactString(string $value): string
    {
        $value = preg_replace('/\bBearer\s+[^\s,;]+/i', 'Bearer [REDACTED]', $value) ?? $value;
        $value = preg_replace('/ACS3-HMAC-SHA256\s+Credential=[^\r\n]+/i', 'ACS3-HMAC-SHA256 [REDACTED]', $value) ?? $value;

        return preg_replace('/([?&](?:PhoneNumbers|TemplateParam|AccessKeyId|AccessKeySecret)=)[^&\s]+/i', '$1[REDACTED]', $value) ?? $value;
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
