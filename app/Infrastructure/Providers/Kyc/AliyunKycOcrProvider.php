<?php

namespace App\Infrastructure\Providers\Kyc;

use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\DTOs\KycOcrRequestDTO;
use App\Domain\Kyc\DTOs\KycOcrResultDTO;
use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Kyc\Enums\KycOcrOutcome;
use App\Infrastructure\Sms\AliyunAcs3Signer;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class AliyunKycOcrProvider implements KycOcrProviderInterface
{
    // Exact OCR API content errors; generic parameter/algorithm errors are not image evidence.
    private const IMAGE_REJECTION_CODES = [
        'unmatchedImageType', 'unsupportedImageFormat', 'illegalImageContent',
        'exceededImageContent', 'illegalImageSize', 'ExceededImageSize', 'ExceededFaceBackCount',
    ];

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
        $phase = 'configuration';
        $status = null;
        $code = null;
        $requestId = null;
        $imageRejected = false;
        try {
            $key = (string) config('kyc.aliyun.access_key_id');
            $secret = (string) config('kyc.aliyun.access_key_secret');
            if (trim($key) === '' || trim($secret) === '') {
                throw new \RuntimeException;
            }
            $phase = 'input';
            if ($contents === '' || strlen($contents) > 10485760) {
                throw new \RuntimeException;
            }
            $phase = 'signing';
            $host = 'ocr-api.cn-hangzhou.aliyuncs.com';
            $headers = ['host' => $host, 'x-acs-action' => $action, 'x-acs-version' => '2021-07-07',
                'x-acs-date' => gmdate('Y-m-d\TH:i:s\Z'), 'x-acs-signature-nonce' => (string) Str::uuid(),
                'x-acs-content-sha256' => hash('sha256', $contents)];
            $headers['Authorization'] = (new AliyunAcs3Signer)->authorization('POST', '/', [], $headers, $key, $secret, $contents);
            $phase = 'transport';
            $response = Http::connectTimeout(10)->timeout(120)->withoutRedirecting()->withHeaders($headers)
                ->withBody($contents, 'application/octet-stream')->post('https://'.$host.'/');
            $status = $response->status();
            $phase = 'response_size';
            if (strlen($response->body()) > 2097152) {
                throw new \RuntimeException;
            }
            $phase = 'response_json';
            // Parse failed HTTP responses too, but never retain their Message or response body.
            $body = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($body)) {
                throw new \RuntimeException;
            }
            $requestId = is_string($body['RequestId'] ?? null) && Str::isUuid($body['RequestId']) && ! in_array($body['RequestId'], [$key, $secret], true) ? $body['RequestId'] : null;
            $code = $this->safeErrorCode($body['Code'] ?? null);
            $phase = 'upstream';
            if (! $response->successful() || isset($body['Code'])) {
                $imageRejected = in_array($code, self::IMAGE_REJECTION_CODES, true)
                    && ($response->successful() || in_array($status, [400, 413, 415, 416], true));
                throw new \RuntimeException;
            }
            $phase = 'response_data';
            $data = $body['Data'] ?? null;
            $data = is_string($data) ? json_decode($data, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING) : $data;
            if (! is_array($data)) {
                throw new \RuntimeException;
            }
            $data['_requestId'] = is_string($body['RequestId'] ?? null) ? substr($body['RequestId'], 0, 255) : null;

            return $data;
        } catch (\Throwable $error) {
            $transportCode = null;
            // cURL's numeric errno is diagnostic; its error text may contain URLs or data.
            if ($phase === 'transport') {
                for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
                    if (($cause instanceof ConnectException || $cause instanceof ConnectionException)
                        && preg_match('/\bcURL error ([0-9]{1,3}):/', $cause->getMessage(), $match) === 1) {
                        $errno = (int) $match[1];
                        $transportCode = $errno <= 100 ? $errno : null;
                        break;
                    }
                }
            }
            try {
                Log::warning('Aliyun KYC OCR failed', ['action' => $action, 'phase' => $phase,
                    'http_status' => $status, 'provider_code' => $code, 'provider_request_id' => $requestId,
                    'transport_code' => $transportCode]);
            } catch (\Throwable) {
                // Logging failures must not leak the original exception or approve the submission.
            }
            if ($imageRejected) {
                // Missing recognized fields produce Failed for either side, never an approval.
                return [];
            }
            throw new \RuntimeException('OCR unavailable.');
        }
    }

    private function safeErrorCode(mixed $code): ?string
    {
        if ($code === null) {
            return null;
        }

        // Do not use an alphanumeric regex: upstream fields could still echo a credential or ID.
        return is_string($code) && in_array($code, [...self::IMAGE_REJECTION_CODES,
            'noPermission', 'NoPermission', 'Forbidden', 'Forbidden.RAM', 'Forbidden.SubUser',
            'InvalidAccessKeyId', 'InvalidAccessKeyId.NotFound', 'InvalidAccessKeyId.Inactive',
            'SignatureDoesNotMatch', 'SignatureNonceUsed', 'InvalidTimeStamp.Expired',
            'InvalidSecurityToken.Expired', 'InvalidSecurityToken.Malformed',
            'ServiceNotOpened', 'ServiceNotOpen', 'ServiceNotEnabled', 'NotOpenService',
            'Throttling', 'Throttling.User', 'Throttling.Api', 'Throttling.System',
            'InvalidParameter', 'InvalidParameter.Image', 'InvalidImage', 'InvalidImageSize',
            'MissingParameter', 'InvalidURL', 'InternalError', 'InternalError.Algo',
            'algorithmError', 'AlgorithmTimeout', 'ServiceTimeout', 'ServiceUnavailable',
            'ocrServiceNotOpen', 'OcrServiceExpired', 'illegalSignature', 'invalidStsToken',
        ], true) ? $code : 'UNRECOGNIZED';
    }
}
