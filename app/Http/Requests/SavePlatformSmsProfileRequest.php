<?php

namespace App\Http\Requests;

final class SavePlatformSmsProfileRequest extends UpdateTenantSmsSettingsRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'name' => ['required', 'string', 'max:100', 'not_regex:/[\x00-\x1f\x7f]/']];
    }
}
