<?php

namespace App\Http\Requests;

use App\Domain\Assets\AssetCatalog;
use App\Domain\Ledger\ValueObjects\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TransferWalletBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asset' => ['required', 'string', Rule::in(AssetCatalog::ASSETS)],
            'request_id' => ['required', 'uuid'],
            'recipient_account_id' => ['required', 'string', 'regex:/^[0-9]{12}$/D'],
            'amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,'.Money::scale(is_string($this->input('asset')) ? $this->input('asset') : '').'})?$/D'],
            'current_password' => ['required', 'current_password:tenant_user'],
            'confirmed' => ['required', 'accepted'],
            'tenant_id' => ['prohibited'], 'user_id' => ['prohibited'], 'wallet_id' => ['prohibited'],
            'sender_wallet_id' => ['prohibited'], 'recipient_wallet_id' => ['prohibited'],
            'sender_user_id' => ['prohibited'], 'recipient_user_id' => ['prohibited'], 'asset_code' => ['prohibited'], 'fee' => ['prohibited'],
        ];
    }
}
