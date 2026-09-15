<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ChangeUserContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['request_id' => ['required', 'uuid'], 'channel' => ['required', 'in:EMAIL,PHONE'],
            'new_contact' => ['required', 'string', 'max:255'], 'current_password' => ['required', 'string', 'max:1024']];
    }
}
