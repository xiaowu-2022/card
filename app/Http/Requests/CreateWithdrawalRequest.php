<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateWithdrawalRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'destination_id' => ['required_without:address', 'uuid', 'prohibits:address'],
            'address' => ['required_without:destination_id', 'string', 'max:100', 'prohibits:destination_id'],
            'confirmed' => ['exclude_without:address', 'required', 'accepted'],
            'amount' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,8})?$/', 'max:21'],
            'expected_fee' => ['sometimes', 'required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,8})?$/'],
            'fee_amount' => ['prohibited'], 'receive_amount' => ['prohibited'], 'hold_amount' => ['prohibited'],
            'asset' => ['prohibited'], 'network' => ['prohibited'], 'wallet_id' => ['prohibited'], 'tenant_id' => ['prohibited'], 'user_id' => ['prohibited'],
        ];
    }
}
