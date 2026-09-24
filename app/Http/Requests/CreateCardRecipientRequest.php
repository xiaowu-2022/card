<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateCardRecipientRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = ['request_id' => ['required', 'uuid'], 'cardholder_application_id' => ['required', 'uuid']];
        foreach (['recipientFirstName' => 40, 'recipientLastName' => 40, 'mobilePrefix' => 8, 'mobile' => 11,
            'country' => 2, 'state' => 50, 'city' => 50, 'addressLine1' => 50, 'postalCode' => 12] as $field => $max) {
            $rules[$field] = ['required', 'string', 'max:'.$max, 'not_regex:/[\p{C}]/u'];
        }
        $rules['country'][] = 'regex:/^[A-Z]{2}$/D';
        $rules['mobilePrefix'][] = 'regex:/^\+?[0-9]{1,4}$/D';
        $rules['mobile'][] = 'regex:/^[0-9]+$/D';
        foreach (['recipientFirstName', 'recipientLastName'] as $field) {
            $rules[$field][] = 'regex:/^[\p{L} ]+$/uD';
        }
        foreach (['addressLine2', 'addressLine3'] as $field) {
            $rules[$field] = ['nullable', 'string', 'max:50', 'not_regex:/[\p{C}]/u'];
        }
        foreach (['recipientId', 'provider_recipient_id', 'tenant_id', 'user_id', 'memberId', 'matrixAccount'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }
}
