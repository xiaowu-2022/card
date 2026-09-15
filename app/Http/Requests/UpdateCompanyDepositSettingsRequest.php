<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCompanyDepositSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'required_security_deposit_amount' => ['required', 'string', 'regex:/\A\d{1,12}(?:\.\d{1,8})?\z/'],
            'security_deposit_refund_wait_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'tenant_id' => ['prohibited'],
            'required_security_deposit_asset' => ['prohibited'],
        ];
    }
}
