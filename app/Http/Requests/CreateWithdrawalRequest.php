<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateWithdrawalRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'], 'destination_id' => ['required', 'uuid'],
            'amount' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,8})?$/', 'max:21'],
            'asset' => ['prohibited'], 'network' => ['prohibited'], 'wallet_id' => ['prohibited'], 'tenant_id' => ['prohibited'], 'user_id' => ['prohibited'],
        ];
    }
}
