<?php

namespace App\Domain\Card\Services;

use App\Support\Errors\DomainException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;

final class CardholderGeography
{
    /** @return array<int,array{code:string,name:string,phone:string}> */
    public function countries(): array
    {
        return json_decode(file_get_contents(public_path('data/card-geography/countries.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    public function countryCodes(): array
    {
        return array_column($this->countries(), 'code');
    }

    /** @return array<int,array<string,mixed>> */
    public function regions(string $country): array
    {
        if (! in_array($country, $this->countryCodes(), true)) {
            return [];
        }

        return json_decode(file_get_contents(public_path('data/card-geography/'.$country.'.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    public function states(string $country): array
    {
        return array_column($this->regions($country), 'value');
    }

    /** @return list<string> */
    public function cities(string $country, string $state): array
    {
        foreach ($this->regions($country) as $region) {
            if ($region['value'] === $state) {
                return array_column($region['cities'], 'value');
            }
        }

        return [];
    }

    /** @param array<string,string> $fields */
    public function assertValid(#[\SensitiveParameter] array $fields): void
    {
        $codes = $this->countryCodes();
        if (! in_array($fields['nationality_country_code'], $codes, true)
            || ! in_array($fields['document_country'], $codes, true)
            || ! $this->validCity($fields['residential_country_code'], $fields['residential_state'], $fields['residential_city'])) {
            throw new DomainException('CARD_SETUP_INVALID', 'Select a valid country, state and city.');
        }
    }

    public function validState(string $country, string $state): bool
    {
        if (! in_array($country, $this->countryCodes(), true)) {
            return false;
        }
        $states = $this->states($country);

        return $states === [] ? $this->validManualLocality($state) : mb_strlen($state) <= 50 && in_array($state, $states, true);
    }

    public function validCity(string $country, string $state, string $city): bool
    {
        if (! $this->validState($country, $state)) {
            return false;
        }
        $cities = $this->cities($country, $state);

        return $cities === [] ? $this->validManualLocality($city) : mb_strlen($city) <= 50 && in_array($city, $cities, true);
    }

    private function validManualLocality(string $value): bool
    {
        return $value === trim($value) && mb_strlen($value) <= 50
            && preg_match('/[\pL\pN]/u', $value) === 1
            && preg_match("/\\A[\\pL\\pM\\pN .,'’()·-]+\\z/u", $value) === 1;
    }

    /** @return array{mobile:?string,mobile_prefix:?string} */
    public function phone(#[\SensitiveParameter] ?string $mobile, ?string $country): array
    {
        if ($mobile === null || trim($mobile) === '') {
            return ['mobile' => null, 'mobile_prefix' => null];
        }
        $prefix = collect($this->countries())->firstWhere('code', $country)['phone'] ?? null;
        if (! $prefix || ! preg_match('/^[0-9 ()-]{4,24}$/', $mobile)) {
            throw new DomainException('CARD_SETUP_INVALID', 'Select a calling code and enter a valid mobile number.');
        }
        $util = PhoneNumberUtil::getInstance();
        try {
            $number = $util->parse('+'.$prefix.preg_replace('/[^0-9]/', '', $mobile), null);
            if ($util->isValidNumber($number)) {
                return ['mobile' => $util->getNationalSignificantNumber($number), 'mobile_prefix' => (string) $number->getCountryCode()];
            }
        } catch (NumberParseException) {
            // Never retain or report the parse exception, which can include contact data.
        }

        throw new DomainException('CARD_SETUP_INVALID', 'Select a calling code and enter a valid mobile number.');
    }
}
