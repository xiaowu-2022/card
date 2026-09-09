<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => str($this->input('slug'))->slug()->lower()->toString(),
            'default_asset' => strtoupper(trim((string) $this->input('default_asset'))),
            'owner_email' => strtolower(trim((string) $this->input('owner_email'))),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'required', 'string', 'min:2', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn(config('tenancy.reserved_slugs')),
                Rule::unique('tenants', 'slug'),
            ],
            'default_locale' => ['required', Rule::in(config('tenancy.supported_locales'))],
            'timezone' => ['required', 'timezone:all'],
            'default_asset' => ['required', Rule::in(config('tenancy.supported_assets'))],
            'owner_email' => ['required', 'email', 'max:255'],
        ];
    }
}
