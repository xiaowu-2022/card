<?php

namespace App\Http\Requests;

use App\Domain\Card\Services\CardholderGeography;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RecognizeKycFrontRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'front_upload_id' => ['required', 'uuid'],
            'document_type' => ['required', 'in:NATIONAL_ID,PASSPORT'],
            'document_country' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/', Rule::in(app(CardholderGeography::class)->countryCodes()), ...($this->input('document_type') === 'NATIONAL_ID' ? ['in:CN'] : [])],
            'reverify' => ['sometimes', 'boolean'],
            'identity_number' => ['exclude'],
            'front_url' => ['prohibited'],
            'image' => ['prohibited'],
        ];
    }
}
