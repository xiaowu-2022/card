<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SendTenantTestEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'test_email' => ['required', 'string', 'email:rfc', 'max:254'],
            'current_password' => ['required', 'string', 'max:255', 'current_password:'.($this->routeIs('platform.*') ? 'platform_admin' : 'tenant_admin')],
            'tenant_id' => ['prohibited'], 'id' => ['prohibited'], 'smtp_token' => ['prohibited'],
            'host' => ['prohibited'], 'port' => ['prohibited'], 'from_address' => ['prohibited'],
        ];
    }
}
