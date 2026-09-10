<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RejectWithdrawalRequest extends FormRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:240']];
    }
}
