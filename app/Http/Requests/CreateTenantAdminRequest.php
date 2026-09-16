<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class CreateTenantAdminRequest extends FormRequest
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
            'role' => ['required', Rule::in(['TENANT_ADMIN', 'KYC_REVIEWER', 'CARD_OPERATOR', 'FINANCE_VIEWER', 'SUPPORT'])],
            'password' => ['required', 'string', 'confirmed', 'max:72', Password::min(12)->letters()->mixedCase()->numbers()],
            'current_password' => $this->routeIs('platform.*') ? ['exclude'] : ['required', 'string', 'current_password:tenant_admin'],
        ];
    }
}
