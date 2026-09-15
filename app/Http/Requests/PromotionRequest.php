<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PromotionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['transfer', 'level'])],
            'request_id' => ['required_if:action,transfer', 'uuid'],
            'current_password' => $this->input('action') === 'transfer' ? ['required', 'current_password:tenant_user'] : ['exclude'],
            'confirmed' => $this->input('action') === 'transfer' ? ['required', 'accepted'] : ['exclude'],
            'member_id' => ['required_if:action,level', 'uuid'],
            'level_id' => ['nullable', 'uuid'],
            'tenant_id' => ['prohibited'], 'user_id' => ['prohibited'], 'wallet_id' => ['prohibited'], 'amount' => ['prohibited'], 'asset' => ['prohibited'],
        ];
    }
}
