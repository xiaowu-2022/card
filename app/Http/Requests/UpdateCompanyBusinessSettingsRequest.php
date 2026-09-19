<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCompanyBusinessSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'required_security_deposit_asset' => ['prohibited'],
            'required_security_deposit_amount' => ['sometimes', 'required', 'string', 'regex:/\A\d{1,12}(?:\.\d{1,8})?\z/'],
            'security_deposit_refund_wait_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            'withdrawal_fee_percent' => ['sometimes', 'required', 'string', 'regex:/\A(?:0|[1-9][0-9]?)(?:\.\d{1,8})?\z/'],
            'allow_wallet_topup' => ['sometimes', 'accepted'],
            'allow_withdrawal' => ['sometimes', 'accepted'],
        ];
    }
}
