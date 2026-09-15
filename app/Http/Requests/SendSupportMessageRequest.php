<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SendSupportMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Scoped route middleware and Application authorization are both required.
    }

    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'support_message' => ['nullable', 'required_without:support_image', 'string', 'max:2000'],
            'support_image' => ['nullable', 'required_without:support_message', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
