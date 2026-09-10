<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateWalletTopupRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'requested_amount' => ['required', 'string', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', 'max:16'],
        ];
    }
}
