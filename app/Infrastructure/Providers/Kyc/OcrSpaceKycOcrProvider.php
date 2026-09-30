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
        try {
            $encrypted = (string) config('kyc.ocr_space.api_key_encrypted');
            $key = $encrypted !== '' ? Crypt::decryptString($encrypted) : '';
            if (trim($key) === '') {
                throw new \RuntimeException;
            }
            $phase = 'input';
            if (strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                throw new \RuntimeException;
            }
            $phase = 'transport';
            $response = Http::connectTimeout(10)->timeout(120)->withoutRedirecting()
                ->withHeaders(['apikey' => $key])->asMultipart()
                ->post('https://api.ocr.space/parse/image', [
                    'url' => $url, 'language' => 'auto', 'OCREngine' => '2',
                    'isOverlayRequired' => 'false', 'isCreateSearchablePdf' => 'false',
                    'detectOrientation' => 'true', 'scale' => 'true',
                ]);
            $status = $response->status();
            $phase = 'response';
            if (! $response->successful() || strlen($response->body()) > 2097152) {
                throw new \RuntimeException;
            }
            $body = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($body) || ($body['IsErroredOnProcessing'] ?? null) !== false
                || ! in_array($body['OCRExitCode'] ?? null, [1, '1'], true)
                || ! empty($body['ErrorMessage']) || ! empty($body['ErrorDetails'])
                || ! is_array($body['ParsedResults'] ?? null) || count($body['ParsedResults']) !== 1) {
                throw new \RuntimeException;
            }
            $page = array_values($body['ParsedResults'])[0];
            if (! is_array($page) || ! in_array($page['FileParseExitCode'] ?? null, [1, '1'], true)
                || ! empty($page['ErrorMessage']) || ! empty($page['ErrorDetails']) || ! is_string($page['ParsedText'] ?? null)) {
                throw new \RuntimeException;
            }

            return $page['ParsedText'];
        } catch (\Throwable) {
            try {
                Log::warning('OCR.Space KYC OCR failed', ['phase' => $phase, 'http_status' => $status]);
            } catch (\Throwable) {
                // Diagnostics cannot expose upstream content or change the closed approval path.
            }
            throw new \RuntimeException('OCR unavailable.');
        }
    }
}
