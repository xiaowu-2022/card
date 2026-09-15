<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssignCompanyDomainsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'domain_ids' => ['present', 'array', 'max:100'],
            'domain_ids.*' => ['required', 'uuid', 'distinct'],
            'original_ids' => ['present', 'array', 'max:100'],
            'original_ids.*' => ['required', 'uuid', 'distinct'],
            'confirmed' => ['required', 'accepted'],
            'tenant_id' => ['prohibited'],
        ];
    }
}
