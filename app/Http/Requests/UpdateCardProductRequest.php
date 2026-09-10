<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCardProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'provider_product_ref' => [
                'required', 'string', 'regex:/^[A-Za-z0-9._-]{4,64}$/',
                Rule::unique('card_products')->where('provider', 'PHOTONPAY')->ignore($this->route('cardProduct')),
            ],
            'minimum_initial_load' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,8})?$/', 'numeric', 'min:20'],
            'minimum_reload' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,8})?$/', 'numeric', 'min:20'],
            'status' => ['required', Rule::in(['DRAFT', 'ACTIVE', 'INACTIVE'])],
        ];
    }
}
