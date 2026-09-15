<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateTenantArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Exact active Tenant Admin scope + settings permission are enforced by middleware.
    }

    public function rules(): array
    {
        return [
            'body' => ['present', 'nullable', 'string', 'max:50000'],
            'tenant_id' => ['prohibited'],
            'id' => ['prohibited'],
            'article_key' => ['prohibited'],
            'locale' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.max' => 'Article content must not exceed 50,000 characters.',
            'body.string' => 'Enter valid article content.',
            'body.present' => 'Enter valid article content.',
        ];
    }
}
