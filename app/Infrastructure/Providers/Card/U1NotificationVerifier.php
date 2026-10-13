<?php

namespace App\Infrastructure\Providers\Card;

use App\Infrastructure\Assets\ExactJson;

final class U1NotificationVerifier
{
    public const FIELDS = [
        'openvcc.success' => ['apiId', 'type', 'cardNo', 'timestamp'],
        'openvcc.failed' => ['apiId', 'type', 'timestamp'],
        'updatevcc.success' => ['cardNo', 'type', 'timestamp'],
        'otp' => ['cardNo', 'type', 'timestamp', 'otp'],
        'rechargevcc.success' => ['apiId', 'type', 'timestamp'],
        'rechargevcc.failed' => ['apiId', 'type', 'timestamp'],
        'withdrawvcc.success' => ['apiId', 'type', 'timestamp'],
        'withdrawvcc.fail' => ['apiId', 'type', 'timestamp'],
        'transaction' => ['type', 'timestamp'],
    ];

    public function verify(#[\SensitiveParameter] string $body, #[\SensitiveParameter] string $key): array
    {
        try {
            $data = ExactJson::decode($body);
            if (! str_starts_with(ltrim($body), '{')) {
                throw new \UnexpectedValueException;
            }
        } catch (\Throwable) {
            return ['verified' => false, 'reason' => 'INVALID_JSON', 'type' => null];
        }
        $type = $data['type'] ?? null;
        if (! is_string($type) || ! isset(self::FIELDS[$type])) {
            return ['verified' => false, 'reason' => 'UNSUPPORTED_TYPE', 'type' => null];
        }
        foreach (self::FIELDS[$type] as $field) {
            if (! is_string($data[$field] ?? null) || $data[$field] === '') {
                return ['verified' => false, 'reason' => 'MISSING_SIGNED_FIELD', 'type' => $type];
            }
        }
        if (! preg_match('/^\d{10,16}$/D', $data['timestamp'])) {
            return ['verified' => false, 'reason' => 'INVALID_TIMESTAMP', 'type' => $type];
        }
        $sign = $data['sign'] ?? null;
        $expected = md5(implode('', array_map(fn ($field) => $data[$field], self::FIELDS[$type])).$key);
        $verified = is_string($sign) && preg_match('/^[a-fA-F0-9]{32}$/D', $sign) && hash_equals($expected, strtolower($sign));

        return ['verified' => (bool) $verified, 'reason' => $verified ? 'VERIFIED' : 'INVALID_SIGNATURE', 'type' => $type];
    }

    /** Raw bodies stay encrypted. Only this recursively redacted projection leaves the server. */
    public function display(#[\SensitiveParameter] string $body): ?array
    {
        try {
            $data = ExactJson::decode($body);
            if (! str_starts_with(ltrim($body), '{')) {
                return null;
            }

            return $this->maskOtp($this->redact($data), is_string($data['otp'] ?? null) && preg_match('/^\d{4,8}$/D', $data['otp']) ? $data['otp'] : null);
        } catch (\Throwable) {
            return null;
        }
    }

    private function maskOtp(array $data, ?string $otp): array
    {
        if ($otp === null) {
            return $data;
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->maskOtp($value, $otp);
            } elseif (is_string($value)) {
                $data[$key] = str_replace($otp, '[REDACTED]', $value);
            }
        }

        return $data;
    }

    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            $normalized = strtolower(str_replace(['_', '-'], '', (string) $key));
            if (in_array($normalized, ['otp', 'code', 'sign', 'apikey', 'key', 'authorization', 'password', 'cvv', 'cvc', 'pan', 'token', 'secret'], true)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            } elseif (is_string($value) && in_array(substr(ltrim($value), 0, 1), ['{', '['], true)) {
                // Some provider fields contain nested JSON strings, including query results.
                try {
                    $data[$key] = json_encode($this->redact(ExactJson::decode($value)), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    $data[$key] = '[REDACTED]';
                }
            }
        }

        return $data;
    }
}
