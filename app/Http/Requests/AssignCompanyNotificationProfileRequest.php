<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssignCompanyNotificationProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'profile_id' => ['present', 'nullable', 'uuid'],
            'tenant_id' => ['prohibited'], 'access_key_id' => ['prohibited'], 'access_key_secret' => ['prohibited'],
            'smtp_token' => ['prohibited'], 'enabled' => ['prohibited'], 'from_address' => ['prohibited'],
        ];
    }
}
