<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class VerifyWithdrawalTransactionRequest extends FormRequest
{
    public function rules(): array
    {
        return ['tx_hash' => ['required', 'string', 'regex:/^[A-Fa-f0-9]{64}$/']];
    }
}
