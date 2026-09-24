<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateCardIssueRequest extends FormRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'card_product_id' => ['required', 'uuid'],
            'form_factor' => ['sometimes', 'in:virtual_card,physical_card'],
            'recipient_application_id' => ['required_if:form_factor,physical_card', 'nullable', 'uuid'],
            'recipientId' => ['prohibited'],
            'cardholder_application_id' => ['required', 'uuid'],
            'initial_load_amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'],
            'opening_fee' => ['prohibited'],
            'provider' => ['prohibited'],
            'cardBin' => ['prohibited'],
            'cardCurrency' => ['prohibited'],
            'cardholderId' => ['prohibited'],
            'wallet_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }
}
