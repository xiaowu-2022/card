<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PromotionDateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('date_from') || $this->filled('date_to')) {
            $this->merge(['date' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'required_with:date_to', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'required_with:date_from', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'tab' => ['prohibited'],
            'kind' => ['sometimes', 'in:all,annual,activation,legacy'],
            'activity' => ['sometimes', 'in:all,invitation,activation,annual'],
            'rank' => ['sometimes', $this->routeIs('user.promotion.commissions') ? 'in:all,unknown,0,1,2,3,4,5,6,7,8' : 'in:all,0,1,2,3,4,5,6,7,8'],
            'relation' => ['sometimes', 'in:all,direct,indirect,unknown'],
            'date' => ['nullable', 'date_format:Y-m-d'], 'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'direct_page' => ['sometimes', 'integer', 'min:1', 'max:1000000'], 'account_id' => ['nullable', 'string', 'max:24', 'regex:/^[0-9]+$/'], 'funding' => ['sometimes', 'in:all,funded,unfunded']];
    }
}
