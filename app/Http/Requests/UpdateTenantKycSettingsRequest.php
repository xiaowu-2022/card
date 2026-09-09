<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTenantKycSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'max_accounts_per_identity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'review_mode' => ['required', Rule::in(['MANUAL'])],
        ];
    }
}
