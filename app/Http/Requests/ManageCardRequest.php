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
        $mutates = in_array($action, ['return', 'freeze', 'unfreeze', 'cancel', 'holder'], true);
        $rules = [
            'action' => ['required', Rule::in(['quote', 'confirm', 'return', 'freeze', 'unfreeze', 'cancel', 'holder', 'holder_details', 'reveal', 'sync', 'refresh', 'history'])],
            'request_id' => [Rule::requiredIf(in_array($action, ['quote', 'return', 'freeze', 'unfreeze', 'cancel', 'holder'], true)), 'nullable', 'uuid'],
            'order_id' => [Rule::requiredIf(in_array($action, ['confirm', 'sync'], true)), 'nullable', 'uuid'],
            'amount' => [Rule::requiredIf(in_array($action, ['quote', 'return'], true)), 'nullable', 'string', 'regex:/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/'],
            'current_password' => [Rule::requiredIf($mutates || $action === 'reveal'), 'nullable', 'string', 'current_password:tenant_user'],
            'confirmed' => [Rule::requiredIf($mutates), ...($mutates ? ['accepted'] : [])],
            'email' => ['sometimes', 'required', 'email:rfc', 'max:40'],
            'mobile' => ['sometimes', 'required', 'string', 'max:24', 'regex:/^[0-9 ()-]{4,24}$/'],
            'mobile_country_code' => ['required_with:mobile', Rule::in($geo->countryCodes())],
        ];
        foreach (['tenant_id', 'user_id', 'card_id', 'wallet_id', 'provider_card_id', 'cardholder_id', 'provider_cardholder_id', 'fee', 'currency', 'asset', 'document_country', 'identity_number', 'firstName', 'lastName'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        foreach (['date_of_birth', 'nationality_country_code', 'residential_country_code', 'residential_state', 'residential_city', 'residential_address', 'residential_postal_code'] as $key) {
            $rules[$key] = ['prohibited'];
        }
        foreach (['legal_first_name', 'legal_last_name'] as $key) {
            $rules[$key] = ['sometimes', 'required', 'string', 'max:40', 'regex:/^[\pL\pM ]+$/u'];
        }

        return $rules;
    }
}
