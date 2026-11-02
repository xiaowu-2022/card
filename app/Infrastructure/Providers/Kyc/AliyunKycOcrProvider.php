<?php

namespace App\Infrastructure\Providers\Kyc;

use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\DTOs\KycOcrRequestDTO;
use App\Domain\Kyc\DTOs\KycOcrResultDTO;
use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Kyc\Enums\KycOcrFailureReason;
use App\Domain\Kyc\Enums\KycOcrOutcome;
use App\Domain\Kyc\Enums\KycOcrSide;
use App\Infrastructure\Sms\AliyunAcs3Signer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class AliyunKycOcrProvider implements KycOcrProviderInterface
{
    public const ENDPOINT = 'https://ocr-api.cn-hangzhou.aliyuncs.com';

    public const VERSION = '2021-07-07';

    // Only explicit image-content failures are failed recognition, rather than an outage.
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
        if ($request->side === KycOcrSide::Back && ($passport || $request->documentCountry !== 'CN')) {
            return $this->rejected(KycOcrFailureReason::BackFields);
        }
        if (! $passport && $request->documentCountry !== 'CN') {
            return $this->rejected(KycOcrFailureReason::NumberFormat);
        }
        $action = $passport
            ? (in_array($request->documentCountry, ['CN', 'HK', 'MO', 'TW'], true) ? 'RecognizeChinesePassport' : 'RecognizePassport')
            : 'RecognizeIdcard';
        $result = $this->recognize($action, $request->side === KycOcrSide::Back ? $request->backUrl : $request->frontUrl);
        $reference = $result['_requestId'] ?? null;
        $data = $result['data'] ?? [];
        if ($request->side === KycOcrSide::Back) {
            if (! isset($data['back']) && isset($data['face'])) {
                return $this->rejected(KycOcrFailureReason::BackSide, $reference);
            }
            $back = $data['back'] ?? [];
            $fields = is_array($back) ? ($back['data'] ?? []) : [];
            $period = is_array($fields) ? ($fields['validPeriod'] ?? null) : null;
            $validity = is_string($period) ? (new NationalIdValidityParser)->parse('有效期限:'.$period) : null;

            return $validity === null ? $this->rejected(KycOcrFailureReason::BackFields, $reference)
                : new KycOcrResultDTO(KycOcrOutcome::Success, providerReference: $reference, validity: $validity);
        }
        if (! $passport && ! isset($data['face']) && isset($data['back'])) {
            return $this->rejected(KycOcrFailureReason::FrontSide, $reference);
        }
        $face = $data['face'] ?? [];
        $fields = $passport ? $data : (is_array($face) ? ($face['data'] ?? []) : []);
        $fields = is_array($fields) ? $fields : [];
        $number = $fields[$passport ? 'passportNumber' : 'idNumber'] ?? null;
        if (is_int($number)) {
            $number = (string) $number;
        }
        if (! is_string($number) || trim($number) === '') {
            return $this->rejected(KycOcrFailureReason::NumberMissing, $reference);
        }
        $text = $passport ? 'PASSPORT NUMBER:'.$number : $number;
        if ($passport) {
            foreach (['mrzLine1', 'mrzLine2'] as $field) {
                if (isset($fields[$field])) {
                    if (! is_string($fields[$field])) {
                        return $this->rejected(KycOcrFailureReason::NumberFormat, $reference);
                    }
                    $text .= "\n".$fields[$field];
                }
            }
        }
        $parsed = (new IdentityDocumentParser)->parse($request, fn (string $unused): string => $text);

        return new KycOcrResultDTO($parsed->outcome, $parsed->candidateIdentityNumber,
            providerReference: $reference, failureReason: $parsed->failureReason);
    }

    private function rejected(KycOcrFailureReason $reason, ?string $reference = null): KycOcrResultDTO
    {
        return new KycOcrResultDTO(KycOcrOutcome::Failed, providerReference: $reference, failureReason: $reason);
    }

    private function recognize(string $action, #[\SensitiveParameter] string $url): array
    {
        $phase = 'configuration';
        $status = null;
        $code = null;
        $reference = null;
        $imageRejected = false;
        try {
            $key = config('kyc.aliyun.access_key_id');
            $secret = config('kyc.aliyun.access_key_secret');
            $token = config('kyc.aliyun.security_token') ?? '';
            if (! is_string($key) || ! is_string($secret) || ! is_string($token)
                || trim($key) === '' || trim($secret) === '' || preg_match('/[\r\n]/', $key.$secret.$token)) {
                throw new \RuntimeException;
            }
            $phase = 'input';
            if (strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL)
                || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
                || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
                throw new \RuntimeException;
            }
            $phase = 'signing';
            $headers = ['host' => 'ocr-api.cn-hangzhou.aliyuncs.com', 'content-type' => 'application/octet-stream',
                'x-acs-action' => $action, 'x-acs-version' => self::VERSION,
                'x-acs-date' => gmdate('Y-m-d\TH:i:s\Z'), 'x-acs-signature-nonce' => (string) Str::uuid(),
                'x-acs-content-sha256' => hash('sha256', '')];
            if ($token !== '') {
                $headers['x-acs-security-token'] = $token;
            }
            $query = ['Url' => $url];
            $signer = new AliyunAcs3Signer;
            $headers['Authorization'] = $signer->authorization('POST', '/', $query, $headers, $key, $secret);
            $phase = 'transport';
            $response = Http::acceptJson()->connectTimeout(10)->timeout(120)->withoutRedirecting()->withHeaders($headers)
                ->withBody('', 'application/octet-stream')->post(self::ENDPOINT.'/?'.$signer->queryString($query));
            $status = $response->status();
            $phase = 'response_size';
            if (strlen($response->body()) > 2097152) {
                throw new \RuntimeException;
            }
            $phase = 'response_json';
            $body = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($body)) {
                throw new \RuntimeException;
            }
            $reference = $this->safeReference($body['RequestId'] ?? null, [$key, $secret, $token]);
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
            if (! is_array($data) || ! is_array($data['data'] ?? null)) {
                throw new \RuntimeException;
            }

            return ['data' => $data['data'], '_requestId' => $reference];
        } catch (\Throwable) {
            try {
                Log::warning('Aliyun KYC OCR failed', ['action' => $action, 'phase' => $phase,
                    'http_status' => $status, 'provider_code' => $code, 'provider_request_id' => $reference]);
            } catch (\Throwable) {
            }
            if ($imageRejected) {
                return ['data' => [], '_requestId' => $reference];
            }
            throw new \RuntimeException('OCR unavailable.');
        }
    }

    private function safeReference(mixed $value, #[\SensitiveParameter] array $credentials): ?string
    {
        return is_string($value) && Str::isUuid($value) && ! in_array($value, $credentials, true) ? $value : null;
    }

    private function safeErrorCode(mixed $code): ?string
    {
        if ($code === null) {
            return null;
        }

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
