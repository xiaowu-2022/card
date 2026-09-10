<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateWithdrawalDestinationRequest extends FormRequest
{
    public function rules(): array
    {
        return ['address' => ['required', 'string', 'max:64'], 'label' => ['nullable', 'string', 'max:80'], 'asset' => ['prohibited'], 'network' => ['prohibited'], 'tenant_id' => ['prohibited'], 'user_id' => ['prohibited']];
    }
}
