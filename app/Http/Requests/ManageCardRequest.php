<?php

namespace App\Http\Requests;

use App\Domain\Card\Services\CardholderGeography;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ManageCardRequest extends FormRequest
{
    public function rules(): array
    {
        $geo = app(CardholderGeography::class);
        $action = $this->input('action');
        $country = is_string($this->input('residential_country_code')) ? $this->input('residential_country_code') : '';
        $state = is_string($this->input('residential_state')) ? $this->input('residential_state') : '';
        $mutates = in_array($action, ['return', 'freeze', 'unfreeze', 'cancel', 'holder'], true);
        $rules = [
            'action' => ['required', Rule::in(['quote', 'confirm', 'return', 'freeze', 'unfreeze', 'cancel', 'holder', 'holder_details', 'reveal', 'sync', 'refresh', 'history'])],
            'request_id' => [Rule::requiredIf(in_array($action, ['quote', 'return', 'freeze', 'unfreeze', 'cancel', 'holder'], true)), 'nullable', 'uuid'],
            'order_id' => [Rule::requiredIf(in_array($action, ['confirm', 'sync'], true)), 'nullable', 'uuid'],
            'amount' => [Rule::requiredIf(in_array($action, ['quote', 'return'], true)), 'nullable', 'string', 'regex:/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/'],
            'current_password' => [Rule::requiredIf($mutates || $action === 'reveal'), 'nullable', 'string', 'current_password:tenant_user'],
            'confirmed' => [Rule::requiredIf($mutates), ...($mutates ? ['accepted'] : [])],
            'email' => ['sometimes', 'required', 'email:rfc', 'max:40'],
            'date_of_birth' => ['sometimes', 'required', 'date_format:Y-m-d', 'before:today'],
            'nationality_country_code' => ['sometimes', 'required', Rule::in($geo->countryCodes())],
            'mobile' => ['sometimes', 'required', 'string', 'max:24', 'regex:/^[0-9 ()-]{4,24}$/'],
            'mobile_country_code' => ['required_with:mobile', Rule::in($geo->countryCodes())],
            'residential_country_code' => ['required_with:residential_address,residential_state,residential_city,residential_postal_code', Rule::in($geo->countryCodes())],
            'residential_state' => ['bail', 'required_with:residential_country_code', 'string', 'max:50', function (string $attribute, mixed $value, \Closure $fail) use ($geo, $country): void {
                if (! $geo->validState($country, $value)) {
                    $fail('Enter a valid state or province, or select one from the available options.');
                }
            }],
            'residential_city' => ['bail', 'required_with:residential_country_code', 'string', 'max:50', function (string $attribute, mixed $value, \Closure $fail) use ($geo, $country, $state): void {
                if (! $geo->validCity($country, $state, $value)) {
                    $fail('Enter a valid city or select one from the available options.');
                }
            }],
            'residential_address' => ['required_with:residential_country_code', 'string', 'max:100'],
            'residential_postal_code' => ['required_with:residential_country_code', 'string', 'max:10', 'regex:/^[A-Za-z0-9 -]+$/'],
        ];
        foreach (['tenant_id', 'user_id', 'card_id', 'wallet_id', 'provider_card_id', 'cardholder_id', 'provider_cardholder_id', 'fee', 'currency', 'asset', 'document_country', 'identity_number', 'firstName', 'lastName', 'legal_first_name', 'legal_last_name'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }
}
