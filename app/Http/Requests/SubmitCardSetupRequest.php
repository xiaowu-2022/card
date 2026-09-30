<?php

namespace App\Http\Requests;

use App\Domain\Card\Services\CardholderGeography;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SubmitCardSetupRequest extends FormRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        $rules = [
            'request_id' => ['required', 'uuid'], 'card_product_id' => ['required', 'uuid'],
            'form_factor' => ['sometimes', Rule::in(['virtual_card', 'physical_card'])],
            'legal_first_name' => ['required', 'string', 'max:40', 'regex:/^[\pL\pM ]+$/u'],
            'legal_last_name' => ['required', 'string', 'max:40', 'regex:/^[\pL\pM ]+$/u'],
            'email' => ['required', 'email:rfc', 'max:40'],
            'mobile' => ['required', 'string', 'max:24', 'regex:/^[0-9 ()-]{4,24}$/'],
            'mobile_country_code' => ['required', Rule::in(app(CardholderGeography::class)->countryCodes())],
        ];
        foreach (['tenant_id', 'user_id', 'provider', 'provider_cardholder_id', 'date_of_birth', 'nationality_country_code',
            'residential_address', 'residential_city', 'residential_state', 'residential_country_code', 'residential_postal_code',
            'document_type', 'document_country', 'identity_number', 'front', 'back', 'front_upload_id', 'back_upload_id',
            'front_url', 'back_url', 'portrait', 'reverse_side', 'mobile_prefix', 'cardholder_name_abbreviation'] as $key) {
            $rules[$key] = ['prohibited'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'legal_first_name.required' => 'Enter the cardholder first name.',
            'legal_last_name.required' => 'Enter the cardholder last name.',
            'email.required' => 'This field is required.',
            'mobile.required' => 'This field is required.',
            'mobile_country_code.required' => 'Select a calling code and enter a valid mobile number.',
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
