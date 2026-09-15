<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ConfigureTenantCardProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'display_name' => ['nullable', 'string', 'max:120'],
            'opening_fee' => ['prohibited'],
            'max_cards_per_user' => ['required', 'integer', 'between:1,100'],
            'status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])],
            'sort_order' => ['required', 'integer', 'between:0,4294967295'],
        ];
    }
}
