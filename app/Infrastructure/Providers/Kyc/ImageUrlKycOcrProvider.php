<?php

namespace App\Infrastructure\Providers\Kyc;

use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\DTOs\KycOcrRequestDTO;
use App\Domain\Kyc\DTOs\KycOcrResultDTO;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class ImageUrlKycOcrProvider implements KycOcrProviderInterface
{
    public const ENDPOINT = 'http://202.95.12.185:9601/ocr';

    public function name(): string
    {
        return 'image_url';
    }

    public function extractIdentityDocument(#[\SensitiveParameter] KycOcrRequestDTO $request): KycOcrResultDTO
    {
        return (new IdentityDocumentParser)->parse($request, fn (string $url): string => $this->recognize($url));
    }

    private function recognize(#[\SensitiveParameter] string $url): string
    {
        $phase = 'input';
        $status = null;
        try {
            if (strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL)
                || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                throw new \RuntimeException;
            }
            $phase = 'transport';
            // The caller supplies only a server-generated original URL. Never forward auth.
            $response = Http::acceptJson()->connectTimeout(10)->timeout(120)->withoutRedirecting()
                ->get(self::ENDPOINT, ['image' => $url]);
            $phase = 'response';
            $status = $response->status();
            if (! $response->successful() || strlen($response->body()) > 2097152) {
                throw new \RuntimeException;
            }
            $body = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($body) || ! is_array($body['texts'] ?? null) || ! array_is_list($body['texts'])
                || ! empty($body['error']) || ! empty($body['errors']) || ($body['success'] ?? true) !== true) {
                throw new \RuntimeException;
            }
            foreach ($body['texts'] as $text) {
                if (! is_string($text)) {
                    throw new \RuntimeException;
                }
            }

            return implode("\n", $body['texts']);
        } catch (\Throwable) {
            try {
                Log::warning('Image URL KYC OCR failed', ['phase' => $phase, 'http_status' => $status]);
            } catch (\Throwable) {
            }
            throw new \RuntimeException('OCR unavailable.');
        }
    }
}
