<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class CreatePlatformAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => strtolower(trim((string) $this->input('email'))), 'name' => trim((string) $this->input('name'))]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(['PLATFORM_ADMIN', 'PLATFORM_AUDITOR'])],
            'password' => ['required', 'string', 'confirmed', 'max:72', Password::min(12)->letters()->mixedCase()->numbers()],
            'current_password' => ['required', 'string', 'current_password:platform_admin'],
        ];
    }
}
