<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateCardProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'balance_limit' => ['nullable', 'string', 'regex:/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/'],
            'opening_fee' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,8})?$/'],
            'name' => ['required', 'string', 'max:120'],
            'card_provider_reference_id' => ['required', 'uuid', 'exists:platform_card_provider_references,id'],
            'provider_product_ref' => ['required', 'string', 'max:64', Rule::unique('card_bin_claims', 'bin')],
            'minimum_initial_load' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,8})?$/', 'numeric', 'min:20'],
            'minimum_reload' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,8})?$/', 'numeric', 'min:20'],
            'status' => ['required', Rule::in(['DRAFT', 'ACTIVE', 'INACTIVE'])],
        ];
    }
}
