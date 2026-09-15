<?php

namespace App\Http\Requests;

use App\Domain\Card\Services\CardholderGeography;
use App\Support\Errors\DomainException;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SubmitCardSetupRequest extends FormRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        $geography = app(CardholderGeography::class);
        $country = is_string($this->input('residential_country_code')) ? $this->input('residential_country_code') : '';
        $state = is_string($this->input('residential_state')) ? $this->input('residential_state') : '';

        return [
            'request_id' => ['required', 'uuid'],
            'card_product_id' => ['required', 'uuid'],
            'legal_first_name' => ['required', 'string', 'max:40', 'regex:/^[\pL ]+$/u'],
            'legal_last_name' => ['required', 'string', 'max:40', 'regex:/^[\pL ]+$/u'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before:today'],
            'nationality_country_code' => ['required', 'string', Rule::in($geography->countryCodes())],
            'residential_address' => ['required', 'string', 'max:100'],
            'residential_city' => ['bail', 'required', 'string', 'max:50', function (string $attribute, mixed $value, Closure $fail) use ($geography, $country, $state): void {
                if (! $geography->validCity($country, $state, $value)) {
                    $fail('Enter a valid city or select one from the available options.');
                }
            }],
            'residential_state' => ['bail', 'required', 'string', 'max:50', function (string $attribute, mixed $value, Closure $fail) use ($geography, $country): void {
                if (! $geography->validState($country, $value)) {
                    $fail('Enter a valid state or province, or select one from the available options.');
                }
            }],
            'residential_country_code' => ['required', 'string', Rule::in($geography->countryCodes())],
            'residential_postal_code' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9 -]+$/'],
            'email' => ['required', 'email:rfc', 'max:40'],
            'mobile' => ['bail', 'nullable', 'string', 'max:24', 'regex:/^[0-9 ()-]{4,24}$/', function (string $attribute, mixed $value, Closure $fail) use ($geography): void {
                $country = $this->input('mobile_country_code');
                if (! is_string($country)) {
                    $fail('Select a calling code and enter a valid mobile number.');

                    return;
                }
                try {
                    $geography->phone($value, $country);
                } catch (DomainException) {
                    $fail('Select a calling code and enter a valid mobile number.');
                }
            }],
            'mobile_country_code' => ['required_with:mobile', 'nullable', 'string', Rule::in($geography->countryCodes())],
            'mobile_prefix' => ['prohibited'],
            'document_type' => ['required', 'in:id_card,passport,resident_permit'],
            'document_country' => ['prohibited'],
            'identity_number' => ['nullable', 'string', 'min:3', 'max:64'],
            'front' => ['required', 'file', 'image', 'mimetypes:image/jpeg,image/png', 'max:6144', 'dimensions:min_width=1,min_height=1,max_width=12000,max_height=12000'],
            'back' => ['required_unless:document_type,passport', 'nullable', 'file', 'image', 'mimetypes:image/jpeg,image/png', 'max:6144', 'dimensions:min_width=1,min_height=1,max_width=12000,max_height=12000'],
            'tenant_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'provider' => ['prohibited'],
            'provider_cardholder_id' => ['prohibited'],
            'portrait' => ['prohibited'],
            'reverse_side' => ['prohibited'],
        ];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return [
            'email.email' => 'Enter a valid email address.',
            'email.max' => 'Email must be at most 40 characters.',
            'mobile.regex' => 'Select a calling code and enter a valid mobile number.',
            'mobile.max' => 'Select a calling code and enter a valid mobile number.',
            'legal_first_name.regex' => 'Use letters and spaces only, up to 40 characters.',
            'legal_first_name.max' => 'Use letters and spaces only, up to 40 characters.',
            'legal_last_name.regex' => 'Use letters and spaces only, up to 40 characters.',
            'legal_last_name.max' => 'Use letters and spaces only, up to 40 characters.',
            'date_of_birth.date_format' => 'Enter a valid birth date before today.',
            'date_of_birth.before' => 'Enter a valid birth date before today.',
            'identity_number.min' => 'Identity number must contain 3 to 64 characters.',
            'identity_number.max' => 'Identity number must contain 3 to 64 characters.',
            'residential_address.max' => 'Address must be at most 100 characters.',
            'residential_postal_code.regex' => 'Use letters, digits, spaces or hyphens, up to 10 characters.',
            'residential_postal_code.max' => 'Use letters, digits, spaces or hyphens, up to 10 characters.',
        ];
    }
}
