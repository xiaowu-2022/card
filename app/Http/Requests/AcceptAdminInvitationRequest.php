<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AcceptAdminInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'confirmed', 'max:1024'],
        ];
    }
}
