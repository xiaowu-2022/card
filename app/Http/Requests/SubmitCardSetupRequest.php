<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitCardSetupRequest extends FormRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'legal_first_name' => ['required', 'string', 'max:40', 'regex:/^[\pL ]+$/u'],
            'legal_last_name' => ['required', 'string', 'max:40', 'regex:/^[\pL ]+$/u'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before:today'],
            'nationality_country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'residential_address' => ['required', 'string', 'max:100'],
            'residential_city' => ['required', 'string', 'max:50'],
            'residential_state' => ['required', 'string', 'max:50'],
            'residential_country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'residential_postal_code' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9 -]+$/'],
            'tenant_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'provider' => ['prohibited'],
            'provider_cardholder_id' => ['prohibited'],
            'identity_number' => ['prohibited'],
            'portrait' => ['prohibited'],
            'reverse_side' => ['prohibited'],
        ];
    }
}
