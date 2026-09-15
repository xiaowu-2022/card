<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TransferWalletBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'recipient_account_id' => ['required', 'string', 'regex:/^[0-9]{12}$/D'],
            'amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D'],
            'current_password' => ['required', 'current_password:tenant_user'],
            'confirmed' => ['required', 'accepted'],
            'tenant_id' => ['prohibited'], 'user_id' => ['prohibited'], 'wallet_id' => ['prohibited'],
            'sender_wallet_id' => ['prohibited'], 'recipient_wallet_id' => ['prohibited'],
            'sender_user_id' => ['prohibited'], 'recipient_user_id' => ['prohibited'], 'asset' => ['prohibited'], 'asset_code' => ['prohibited'], 'fee' => ['prohibited'],
        ];
    }
}
