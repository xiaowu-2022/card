<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateWalletTopupRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'wallet_id' => ['required', 'uuid'],
            'amount' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,8})?$/', 'max:21'],
            'asset' => ['required', 'string', 'regex:/^[A-Z0-9]{3,12}$/'],
        ];
    }
}
