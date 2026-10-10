<?php

namespace App\Http\Requests;

use App\Domain\Admin\Services\AdminLoginAccount;
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
        $this->merge(['email' => AdminLoginAccount::normalize((string) $this->input('email')), 'name' => trim((string) $this->input('name'))]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => AdminLoginAccount::rules(),
            'role' => ['required', Rule::in(['PLATFORM_ADMIN', 'PLATFORM_AUDITOR'])],
            'password' => ['required', 'string', 'confirmed', 'max:72', Password::min(6)],
        ];
    }
}
