<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CardTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'tenant_id' => ['prohibited'], 'user_id' => ['prohibited'],
            'provider_card_id' => ['prohibited'], 'page_size' => ['prohibited'],
        ];
    }
}
