<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTenantLocalesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled_locales' => ['required', 'array', 'min:1'],
            'enabled_locales.*' => ['required', 'distinct', Rule::in(config('tenancy.supported_locales'))],
            'default_locale' => ['required', Rule::in(config('tenancy.supported_locales'))],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            if (! in_array($this->input('default_locale'), $this->input('enabled_locales', []), true)) {
                $validator->errors()->add('default_locale', 'The default locale must be enabled.');
            }
        }];
    }
}
