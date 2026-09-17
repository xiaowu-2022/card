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
        return ['channel' => ['required', 'in:EMAIL'], 'reset_contact' => ['required', 'string', 'max:255', 'email'], 'request_id' => ['required', 'uuid']];
    }
}
