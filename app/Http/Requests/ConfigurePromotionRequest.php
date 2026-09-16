<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ConfigurePromotionRequest extends FormRequest
{
    public function rules(): array
    {
        return ['action' => ['required', Rule::in([])],
            'rank' => ['required_if:action,level', 'integer', 'min:1', 'max:1000000'],
            'name' => ['required_if:action,level', 'string', 'max:80'],
            'reward' => ['required_if:action,level', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.0{1,8})?$/D'],
            'revision' => ['nullable', 'integer', 'min:0'],
            'account_id' => ['required_if:action,member', 'string', 'size:12'],
            'level_id' => ['nullable', 'uuid'], 'tenant_id' => ['prohibited'], 'balance' => ['prohibited']];
    }
}
