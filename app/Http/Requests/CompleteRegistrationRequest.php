<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class CompleteRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_id' => ['prohibited'],
            'accountId' => ['prohibited'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'locale' => ['nullable', 'string', 'max:16'],
            'password' => ['required', 'confirmed', Password::min(6)],
        ];
    }
}
