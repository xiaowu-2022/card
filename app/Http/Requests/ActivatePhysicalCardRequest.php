<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ActivatePhysicalCardRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'expiration_date' => ['required', 'string', 'regex:/^(0[1-9]|1[0-2])\/[0-9]{2}$/D'],
            // The API specifies string(8), not a numeric-only PIN policy. Leave issuer-specific restrictions to PhotonPay.
            'pin' => ['required', 'string', 'max:8', 'not_regex:/[\p{C}]/u'],
            'pin_confirmation' => ['required', 'string', 'same:pin'],
            'current_password' => ['required', 'string', 'current_password:tenant_user'],
            'confirmed' => ['required', 'accepted'],
            'cardId' => ['prohibited'], 'provider_card_id' => ['prohibited'],
        ];
    }
}
