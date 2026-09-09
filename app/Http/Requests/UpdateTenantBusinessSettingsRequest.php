<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTenantBusinessSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['required_security_deposit_asset' => strtoupper(trim((string) $this->input('required_security_deposit_asset')))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'required_security_deposit_amount' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,8})?$/'],
            'required_security_deposit_asset' => ['required', Rule::in(config('tenancy.supported_assets'))],
            'allow_wallet_topup' => ['required', 'boolean'],
            'allow_withdrawal' => ['required', 'boolean'],
        ];
    }
}
