<?php

namespace App\Infrastructure\Providers\Kyc;

use App\Domain\Kyc\DTOs\KycOcrRequestDTO;
use App\Domain\Kyc\DTOs\KycOcrResultDTO;
use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Kyc\Enums\KycOcrFailureReason;
use App\Domain\Kyc\Enums\KycOcrOutcome;
use Illuminate\Support\Facades\Log;

/** Text extraction is not document authenticity or holder verification. */
final class OcrSpaceDocumentParser
{
    public function parse(#[\SensitiveParameter] KycOcrRequestDTO $request, callable $recognize): KycOcrResultDTO
    {
        $passport = $request->documentType === KycDocumentType::Passport;
        if (! $passport && $request->documentCountry !== 'CN') {
            return $this->reject(KycOcrFailureReason::NumberFormat);
        }
        $front = $this->text($recognize($request->frontUrl));
        if ($passport) {
            $number = $this->passportNumber($front);

            return $number === null ? $this->reject(KycOcrFailureReason::NumberMissing)
                : new KycOcrResultDTO(KycOcrOutcome::Success, $number);
        }
        preg_match_all('/(?<![A-Z0-9])[1-9][0-9]{16}[0-9X](?![A-Z0-9])/', $front, $matches);
        $numbers = array_values(array_unique($matches[0]));
        if (count($numbers) !== 1) {
            return $this->reject(KycOcrFailureReason::NumberFormat);
        }
        $number = $numbers[0];
        $sum = 0;
        foreach ([7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2] as $i => $weight) {
            $sum += (int) $number[$i] * $weight;
        }
        if ($number[17] !== '10X98765432'[$sum % 11]) {
            return $this->reject(KycOcrFailureReason::NumberChecksum);
        }
        if (! checkdate((int) substr($number, 10, 2), (int) substr($number, 12, 2), (int) substr($number, 6, 4))) {
            return $this->reject(KycOcrFailureReason::NumberFormat);
        }

        return new KycOcrResultDTO(KycOcrOutcome::Success, $number);
    }

    private function text(#[\SensitiveParameter] string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text;
        }

        // Preserve line boundaries so unrelated fields cannot become one number.
        return mb_strtoupper((string) preg_replace('/[^\S\r\n]+/u', '', $text), 'UTF-8');
    }

    private function passportNumber(#[\SensitiveParameter] string $text): ?string
    {
        $numbers = [];
        // Require an explicit passport-number label; never guess an arbitrary alphanumeric token.
        preg_match_all('/(?:PASSPORT(?:NO\.?|NUMBER)|护照号码|护照号)[:：]?\s*([A-Z0-9]{3,20})(?![A-Z0-9])/u', $text, $labels);
        foreach ($labels[1] as $number) {
            if (preg_match('/[0-9]/', $number)) {
                $numbers[] = $number;
            }
        }
        // TD3 passport MRZ: a passport header and a complete second line, including number check digit.
        $lines = preg_split('/\R/u', $text) ?: [];
        foreach ($lines as $i => $line) {
            if (! preg_match('/^P[A-Z<][A-Z<]{3}[A-Z<]{39}$/D', $line)) {
                continue;
            }
            $mrz = $lines[$i + 1] ?? '';
            if (! preg_match('/^[A-Z0-9<]{9}[0-9][A-Z<]{3}[0-9]{6}[0-9][MFX<][0-9]{6}[0-9][A-Z0-9<]{14}[0-9<][0-9]$/D', $mrz)) {
                return null;
            }
            $field = substr($mrz, 0, 9);
            $sum = 0;
            for ($j = 0; $j < 9; $j++) {
                $char = $field[$j];
                $sum += ($char === '<' ? 0 : (ctype_digit($char) ? (int) $char : ord($char) - 55)) * [7, 3, 1][$j % 3];
            }
            $number = rtrim($field, '<');
            if ($sum % 10 !== (int) $mrz[9] || ! preg_match('/^[A-Z0-9]{3,9}$/D', $number)) {
                return null;
            }
            $numbers[] = $number;
        }
        $numbers = array_values(array_unique($numbers));

        return count($numbers) === 1 ? $numbers[0] : null;
    }

    private function reject(KycOcrFailureReason $reason): KycOcrResultDTO
    {
        try {
            Log::warning('OCR.Space KYC OCR rejected', ['reason' => $reason->value]);
        } catch (\Throwable) {
        }

        return new KycOcrResultDTO(KycOcrOutcome::Failed, failureReason: $reason);
    }
}
