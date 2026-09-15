<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantEmailSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Active exact tenant membership and settings permission required by middleware.
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'from_address' => ['nullable', 'required_if:enabled,true', 'string', 'email:rfc', 'max:254', 'regex:/^[\x21-\x7E]+$/D'],
            'from_name' => ['nullable', 'required_if:enabled,true', 'string', 'max:100', 'not_regex:/[\x00-\x1f\x7f]/'],
            'smtp_token' => ['nullable', 'string', 'max:512', 'regex:/^[\x21-\x7E]+$/D'],
            'daily_recipient_limit' => ['required', 'integer', 'between:0,1000'],
            'current_password' => ['required', 'string', 'max:255', 'current_password:'.($this->routeIs('platform.*') ? 'platform_admin' : 'tenant_admin')],
            'tenant_id' => ['prohibited'], 'id' => ['prohibited'], 'host' => ['prohibited'], 'port' => ['prohibited'],
            'encryption' => ['prohibited'], 'smtp_username' => ['prohibited'], 'provider' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['daily_recipient_limit.between' => 'Use a whole number from 0 to 1000.', 'daily_recipient_limit.integer' => 'Use a whole number from 0 to 1000.'];
    }
}
