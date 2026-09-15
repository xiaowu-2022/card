<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RefundSecurityDepositRequest extends FormRequest
{
    public function rules(): array
    {
        return ['action' => ['required', Rule::in(['request', 'cancel'])],
            'request_id' => ['required_if:action,request', 'uuid'], 'refund_id' => ['required_unless:action,request', 'uuid'],
            'current_password' => ['required', 'current_password:tenant_user'], 'confirmed' => ['required', 'accepted'],
            'amount' => ['prohibited'], 'asset' => ['prohibited'], 'tenant_id' => ['prohibited'], 'user_id' => ['prohibited'], 'wallet_id' => ['prohibited'],
            'refund_wait_days' => ['prohibited'], 'refund_eligible_at' => ['prohibited']];
    }
}
