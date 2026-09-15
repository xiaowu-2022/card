<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateTenantBusinessSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'required_security_deposit_amount' => ['prohibited'],
            'required_security_deposit_asset' => ['prohibited'],
            'security_deposit_refund_wait_days' => ['prohibited'],
            'allow_wallet_topup' => ['sometimes', 'accepted'],
            'allow_withdrawal' => ['sometimes', 'accepted'],
            'withdrawal_fixed_fee' => ['sometimes', 'required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
        ];
    }
}
