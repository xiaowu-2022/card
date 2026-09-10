<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class FundSecurityDepositRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'expected_remaining' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,8})?$/', 'max:21'],
            'amount' => ['prohibited'],
            'asset' => ['prohibited'],
            'wallet_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }
}
