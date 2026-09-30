<?php

namespace App\Infrastructure\Providers\Kyc;

use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\DTOs\KycOcrRequestDTO;
use App\Domain\Kyc\DTOs\KycOcrResultDTO;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class OcrSpaceKycOcrProvider implements KycOcrProviderInterface
{
    public function name(): string
    {
        return 'ocr_space';
    }

    public function extractIdentityDocument(#[\SensitiveParameter] KycOcrRequestDTO $request): KycOcrResultDTO
    {
        return (new OcrSpaceDocumentParser)->parse($request, fn (string $url): string => $this->recognize($url));
    }

    private function recognize(#[\SensitiveParameter] string $url): string
    {
        $phase = 'configuration';
        $status = null;
        $reason = 'credentials_unavailable';
        $diagnostics = [];
        try {
            $encrypted = (string) config('kyc.ocr_space.api_key_encrypted');
            $key = $encrypted !== '' ? Crypt::decryptString($encrypted) : '';
            if (trim($key) === '') {
                throw new \RuntimeException;
            }
            $phase = 'input';
            $reason = 'invalid_original_url';
            if (strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                throw new \RuntimeException;
            }
            $phase = 'transport';
            $reason = 'request_failed';
            $response = Http::connectTimeout(10)->timeout(120)->withoutRedirecting()
                ->withHeaders(['apikey' => $key])->asMultipart()
                ->post('https://api.ocr.space/parse/image', [
                    'url' => $url, 'language' => 'auto', 'OCREngine' => '2',
                    'isOverlayRequired' => 'false', 'isCreateSearchablePdf' => 'false',
                    'detectOrientation' => 'true', 'scale' => 'true',
                ]);
            $status = $response->status();
            $phase = 'response';
            $reason = 'http_error';
            if (! $response->successful()) {
                throw new \RuntimeException;
            }
            $reason = 'response_too_large';
            if (strlen($response->body()) > 2097152) {
                throw new \RuntimeException;
            }
            $reason = 'invalid_json';
            $body = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
            $reason = 'provider_result_rejected';
            if (is_array($body)) {
                $pages = $body['ParsedResults'] ?? null;
                $page = is_array($pages) && count($pages) === 1 ? array_values($pages)[0] : null;
                $diagnostics = [
                    'ocr_exit_code' => $this->safeCode($body['OCRExitCode'] ?? null, [1, 2, 3, 4, 99]),
                    'file_parse_exit_code' => $this->safeCode(is_array($page) ? ($page['FileParseExitCode'] ?? null) : null, [0, 1, -10, -20, -30, -99]),
                    'provider_error_category' => $this->errorCategory($body, is_array($page) ? $page : []),
                ];
            }
            if (! is_array($body) || ($body['IsErroredOnProcessing'] ?? null) !== false
                || ! in_array($body['OCRExitCode'] ?? null, [1, '1'], true)
                || ! empty($body['ErrorMessage']) || ! empty($body['ErrorDetails'])
                || ! is_array($body['ParsedResults'] ?? null) || count($body['ParsedResults']) !== 1) {
                throw new \RuntimeException;
            }
            $page = array_values($body['ParsedResults'])[0];
            $reason = 'page_result_rejected';
            if (! is_array($page) || ! in_array($page['FileParseExitCode'] ?? null, [1, '1'], true)
                || ! empty($page['ErrorMessage']) || ! empty($page['ErrorDetails']) || ! is_string($page['ParsedText'] ?? null)) {
                throw new \RuntimeException;
            }

            return $page['ParsedText'];
        } catch (\Throwable) {
            try {
                Log::warning('OCR.Space KYC OCR failed', ['phase' => $phase, 'http_status' => $status, 'reason' => $reason] + $diagnostics);
            } catch (\Throwable) {
                // Diagnostics cannot expose upstream content or change the closed approval path.
            }
            throw new \RuntimeException('OCR unavailable.');
        }
    }

    private function safeCode(mixed $value, array $allowed): ?int
    {
        foreach ($allowed as $code) {
            if ($value === $code || $value === (string) $code) {
                return $code;
            }
        }

        return null;
    }

    /** Classify upstream error fields only; never return their content or ParsedText. */
    private function errorCategory(#[\SensitiveParameter] array $body, #[\SensitiveParameter] array $page): string
    {
        $messages = [];
        foreach ([$body, $page] as $result) {
            foreach (['ErrorMessage', 'ErrorDetails'] as $field) {
                $value = $result[$field] ?? null;
                foreach (is_array($value) ? array_slice($value, 0, 10) : [$value] as $message) {
                    if (is_string($message)) {
                        $messages[] = substr($message, 0, 4096);
                    }
                }
            }
        }
        $text = strtolower(implode(' ', $messages));
        // These are diagnostic hints from known phrases, not approval decisions.
        foreach ([
            'api_key_rejected' => ['invalid api key', 'invalid apikey', 'api key is invalid', 'apikey is invalid'],
            'quota_or_rate_limit' => ['rate limit', 'maximum number of', 'quota', 'requests per'],
            'file_size_limit' => ['file size limit', 'maximum file size', 'file is too large', 'file size exceeds'],
            'file_type_error' => ['file type', 'filetype', 'file format', 'content-type', 'content type'],
            'image_download_failed' => ['unable to download', 'could not download', 'failed to download', 'error downloading', 'file not found'],
            'provider_timeout' => ['timed out', 'timeout'],
        ] as $category => $phrases) {
            foreach ($phrases as $phrase) {
                if (str_contains($text, $phrase)) {
                    return $category;
                }
            }
        }

        return 'unclassified';
    }
}
