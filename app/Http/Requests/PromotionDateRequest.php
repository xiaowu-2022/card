<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PromotionDateRequest extends FormRequest
{
    public function rules(): array
    {
        return ['date' => ['nullable', 'date_format:Y-m-d'], 'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'direct_page' => ['sometimes', 'integer', 'min:1', 'max:1000000'], 'account_id' => ['nullable', 'string', 'max:24', 'regex:/^[0-9]+$/'], 'funding' => ['sometimes', 'in:all,funded,unfunded']];
    }
}
