<?php

namespace App\Infrastructure\Providers\Kyc;

use App\Domain\Kyc\DTOs\KycDocumentValidityDTO;
use App\Domain\Kyc\Services\DocumentValidity;
use App\Support\Errors\DomainException;

/** Only labelled validity periods are evidence; other printed dates are not. */
final class NationalIdValidityParser
{
    public function parse(#[\SensitiveParameter] string $text): ?KycDocumentValidityDTO
    {
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text;
        }
        $text = (string) preg_replace('/[^\S\r\n]+/u', '', $text);
        if (! preg_match_all('/有效(?:期限|日期|期)[:：]?/u', $text, $labels, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $date = '(?:[0-9]{4}(?:[.\/-][0-9]{1,2}[.\/-][0-9]{1,2}|年[0-9]{1,2}月[0-9]{1,2}日?)|[0-9]{8})';
        $periods = [];
        foreach ($labels[0] as $i => [$label, $offset]) {
            $start = $offset + strlen($label);
            $length = isset($labels[0][$i + 1]) ? $labels[0][$i + 1][1] - $start : strlen($text) - $start;
            $section = substr($text, $start, $length);
            if (! preg_match('/^\s*(?<from>'.$date.')\s*[-—–－~～至到]+\s*(?<until>'.$date.'|长期)(?=\s|$)/u', $section, $match)) {
                return null;
            }
            // Multiple conflicting periods or stray date fields must not be silently ignored.
            $rest = substr($section, strlen($match[0]));
            if (preg_match('/'.$date.'|长期/u', $rest)) {
                return null;
            }
            $from = $this->date($match['from']);
            $longTerm = $match['until'] === '长期';
            $until = $longTerm ? null : $this->date($match['until']);
            if ($from === null || (! $longTerm && $until === null)) {
                return null;
            }
            $validity = new KycDocumentValidityDTO($from, $until, $longTerm);
            try {
                (new DocumentValidity)->assertStructure($validity);
            } catch (DomainException) {
                return null;
            }
            $periods[$from.'|'.($until ?? '长期')] = $validity;
        }

        return count($periods) === 1 ? array_values($periods)[0] : null;
    }

    private function date(string $value): ?string
    {
        if (preg_match('/^([0-9]{4})([0-9]{2})([0-9]{2})$/D', $value, $parts)
            || preg_match('/^([0-9]{4})[.\/年-]([0-9]{1,2})[.\/月-]([0-9]{1,2})日?$/uD', $value, $parts)) {
            if (checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                return sprintf('%04d-%02d-%02d', (int) $parts[1], (int) $parts[2], (int) $parts[3]);
            }
        }

        return null;
    }
}
