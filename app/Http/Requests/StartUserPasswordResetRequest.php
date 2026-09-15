<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StartUserPasswordResetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['channel' => ['required', 'in:EMAIL,PHONE'], 'reset_contact' => ['required', 'string', 'max:255'], 'request_id' => ['required', 'uuid']];
    }
}
