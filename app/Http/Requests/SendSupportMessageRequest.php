<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AcceptsDirectImages;
use Illuminate\Foundation\Http\FormRequest;

final class SendSupportMessageRequest extends FormRequest
{
    use AcceptsDirectImages;

    public function authorize(): bool
    {
        return true; // Scoped route middleware and Application authorization are both required.
    }

    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'support_message' => ['nullable', 'required_without_all:support_image,support_image_upload_id', 'string', 'max:2000'],
            ...$this->imageRules('support_image', ! $this->filled('support_message'), ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']),
        ];
    }
}
