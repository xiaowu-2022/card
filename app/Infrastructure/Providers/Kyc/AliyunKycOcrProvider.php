<?php

namespace App\Infrastructure\Providers\Kyc;

use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\DTOs\KycOcrRequestDTO;
use App\Domain\Kyc\DTOs\KycOcrResultDTO;
use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Kyc\Enums\KycOcrOutcome;
use App\Infrastructure\Sms\AliyunAcs3Signer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class AliyunKycOcrProvider implements KycOcrProviderInterface
{
    public function name(): string
    {
        return 'aliyun';
    }

    public function extractIdentityDocument(#[\SensitiveParameter] KycOcrRequestDTO $request): KycOcrResultDTO
    {
        $passport = $request->documentType === KycDocumentType::Passport;
        if (! $passport && $request->documentCountry !== 'CN') {
            return new KycOcrResultDTO(KycOcrOutcome::Failed);
        }
        $result = $this->recognize($passport ? (in_array($request->documentCountry, ['CN', 'HK', 'MO', 'TW'], true) ? 'RecognizeChinesePassport' : 'RecognizePassport') : 'RecognizeIdcard', $request->frontContents);
        $data = $passport ? ($result['data'] ?? []) : ($result['data']['face']['data'] ?? []);
        $number = $data[$passport ? 'passportNumber' : 'idNumber'] ?? null;
        if (is_int($number)) {
            $number = (string) $number;
        }
        if (! is_string($number) || ! preg_match($passport ? '/^[A-Z0-9]{3,20}$/D' : '/^[1-9][0-9]{16}[0-9X]$/D', strtoupper($number))) {
            return new KycOcrResultDTO(KycOcrOutcome::Failed);
        }
        if (! $passport) {
            $number = strtoupper($number);
            $sum = 0;
            foreach ([7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2] as $i => $weight) {
                $sum += (int) $number[$i] * $weight;
            }
            if ($number[17] !== '10X98765432'[$sum % 11]) {
                return new KycOcrResultDTO(KycOcrOutcome::Failed);
            }
            $back = $this->recognize('RecognizeIdcard', $request->backContents);
            if (empty($back['data']['back']['data']['issueAuthority']) || empty($back['data']['back']['data']['validPeriod'])) {
                return new KycOcrResultDTO(KycOcrOutcome::Failed);
            }
        }

        return new KycOcrResultDTO(KycOcrOutcome::Success, strtoupper($number), $data['name'] ?? $data['nameEn'] ?? null, providerReference: $result['_requestId'] ?? null);
    }

    private function recognize(string $action, #[\SensitiveParameter] string $contents): array
    {
        $key = (string) config('kyc.aliyun.access_key_id');
        $secret = (string) config('kyc.aliyun.access_key_secret');
        if ($key === '' || $secret === '' || $contents === '' || strlen($contents) > 10485760) {
            throw new \RuntimeException('OCR unavailable.');
        }
        $host = 'ocr-api.cn-hangzhou.aliyuncs.com';
        $headers = ['host' => $host, 'x-acs-action' => $action, 'x-acs-version' => '2021-07-07',
            'x-acs-date' => gmdate('Y-m-d\TH:i:s\Z'), 'x-acs-signature-nonce' => (string) Str::uuid(),
            'x-acs-content-sha256' => hash('sha256', $contents)];
        $headers['Authorization'] = (new AliyunAcs3Signer)->authorization('POST', '/', [], $headers, $key, $secret, $contents);
        try {
            $response = Http::connectTimeout(5)->timeout(20)->withoutRedirecting()->withHeaders($headers)
                ->withBody($contents, 'application/octet-stream')->post('https://'.$host.'/');
            if (! $response->successful() || strlen($response->body()) > 2097152) {
                throw new \RuntimeException;
            }
            $body = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (isset($body['Code'])) {
                throw new \RuntimeException;
            }
            $data = $body['Data'] ?? null;
            $data = is_string($data) ? json_decode($data, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING) : $data;
            if (! is_array($data)) {
                throw new \RuntimeException;
            }
            $data['_requestId'] = is_string($body['RequestId'] ?? null) ? substr($body['RequestId'], 0, 255) : null;

            return $data;
        } catch (\Throwable) {
            // Never attach the upstream exception, images, response or credentials.
            throw new \RuntimeException('OCR unavailable.');
        }
    }
}
