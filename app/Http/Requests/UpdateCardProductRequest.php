<?php

namespace App\Http\Requests;

use App\Domain\CardProduct\Models\CardProduct;
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
        $product = CardProduct::query()->findOrFail($this->route('cardProduct'));
        $binding = $this->exists('card_provider_reference_id') ? $this->input('card_provider_reference_id') : $product->card_provider_reference_id;
        $provider = $binding !== $product->card_provider_reference_id ? 'UNCONFIGURED' : $product->provider;

        return [
            'opening_fee' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,8})?$/'],
            'name' => ['required', 'string', 'max:120'],
            'card_provider_reference_id' => ['nullable', 'uuid', 'exists:platform_card_provider_references,id'],
            'provider_product_ref' => [
                'nullable', 'string', 'regex:/^[A-Za-z0-9._-]{4,64}$/',
                Rule::unique('card_products')->whereNull('archived_at')->where('provider', $provider)->where('card_provider_reference_id', $binding)->ignore($product->id),
            ],
            'minimum_initial_load' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,8})?$/', 'numeric', 'min:20'],
            'minimum_reload' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,8})?$/', 'numeric', 'min:20'],
            'status' => ['required', Rule::in(['DRAFT', 'ACTIVE', 'INACTIVE'])],
        ];
    }
}
