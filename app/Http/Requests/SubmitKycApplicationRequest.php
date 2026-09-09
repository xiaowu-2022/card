<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitKycApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxKilobytes = (int) config('kyc.document_max_mb') * 1024;

        return [
            'document_country' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'identity_number' => ['required', 'string', 'min:3', 'max:128'],
            'front' => ['required', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', "max:{$maxKilobytes}", 'dimensions:min_width=1,min_height=1'],
            'back' => ['required', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', "max:{$maxKilobytes}", 'dimensions:min_width=1,min_height=1'],
        ];
    }
}
