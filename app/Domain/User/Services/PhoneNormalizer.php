<?php

namespace App\Domain\User\Services;

use App\Support\Errors\DomainException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

final class PhoneNormalizer
{
    public function normalize(string $phone, ?string $region = null): string
    {
        try {
            $util = PhoneNumberUtil::getInstance();
            $number = $util->parse(trim($phone), $region ? strtoupper($region) : null);
            if (! $util->isValidNumber($number)) {
                throw new DomainException('INVALID_PHONE', 'Enter a valid international phone number.', 422);
            }

            return $util->format($number, PhoneNumberFormat::E164);
        } catch (NumberParseException) {
            throw new DomainException('INVALID_PHONE', 'Enter a valid international phone number.', 422);
        }
    }
}
