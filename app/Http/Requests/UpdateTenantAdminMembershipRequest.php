<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTenantAdminMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(['TENANT_ADMIN', 'KYC_REVIEWER', 'CARD_OPERATOR', 'FINANCE_VIEWER', 'SUPPORT'])],
            'status' => ['required', Rule::in(['ACTIVE', 'SUSPENDED'])],
            'current_password' => ['required', 'string', 'current_password:platform_admin'],
            'tenant_id' => ['prohibited'], 'admin_user_id' => ['prohibited'],
            'name' => ['prohibited'], 'email' => ['prohibited'], 'password' => ['prohibited'],
        ];
    }
}
