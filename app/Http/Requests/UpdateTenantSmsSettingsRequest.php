<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantSmsSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Exact tenant membership and settings permission are enforced by middleware.
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'access_key_id' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9]+$/D', 'required_with:access_key_secret'],
            'access_key_secret' => ['nullable', 'string', 'max:256', 'regex:/^[A-Za-z0-9+\/=._-]+$/D', 'required_with:access_key_id'],
            'sign_name' => ['nullable', 'required_if:enabled,true', 'string', 'max:100', 'not_regex:/[\x00-\x1f\x7f]/'],
            'verification_template_code' => ['nullable', 'required_if:enabled,true', 'string', 'max:100', 'regex:/^SMS_[0-9]+$/D'],
            'existing_account_template_code' => ['nullable', 'string', 'max:100', 'regex:/^SMS_[0-9]+$/D', 'different:verification_template_code'],
            'resend_interval_seconds' => ['required', 'integer', 'between:60,3600'],
            'code_ttl_seconds' => ['required', 'integer', 'between:60,3600', 'gte:resend_interval_seconds'],
            'current_password' => ['required', 'string', 'max:255', 'current_password:'.($this->routeIs('platform.*') ? 'platform_admin' : 'tenant_admin')],
            'tenant_id' => ['prohibited'],
            'id' => ['prohibited'],
            'endpoint' => ['prohibited'],
            'provider' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'access_key_id.required_with' => 'Replace both AccessKey fields together.',
            'access_key_secret.required_with' => 'Replace both AccessKey fields together.',
            'resend_interval_seconds.between' => 'Use a whole number from 60 to 3600 seconds.',
            'resend_interval_seconds.integer' => 'Use a whole number from 60 to 3600 seconds.',
            'code_ttl_seconds.between' => 'Use a whole number from 60 to 3600 seconds.',
            'code_ttl_seconds.integer' => 'Use a whole number from 60 to 3600 seconds.',
            'code_ttl_seconds.gte' => 'Code validity must not be shorter than the send interval.',
            'existing_account_template_code.different' => 'Use a separate template for existing-account notices.',
        ];
    }
}
