<?php

namespace App\Http\Requests;

use App\Domain\Card\Services\CardholderGeography;
use App\Http\Requests\Concerns\AcceptsDirectImages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SubmitKycApplicationRequest extends FormRequest
{
    use AcceptsDirectImages;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // JSON uses booleans; form/older clients may serialize the same flag as text.
        $value = $this->input('reverify');
        if ($value === 'true' || $value === 'false') {
            $this->merge(['reverify' => $value === 'true']);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxKilobytes = (int) config('kyc.document_max_mb') * 1024;

        return [
            'document_type' => ['required', 'in:NATIONAL_ID,PASSPORT'],
            'document_country' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/', Rule::in(app(CardholderGeography::class)->countryCodes()), ...($this->input('document_type') === 'NATIONAL_ID' ? ['in:CN'] : [])],
            'front_url' => ['nullable', Rule::prohibitedIf(! $this->filled('front_upload_id')), 'url:https', 'max:2048'],
            'back_url' => ['nullable', Rule::prohibitedIf(! $this->filled('back_upload_id')), 'url:https', 'max:2048'],
            'identity_number' => ['exclude'],
            'reverify' => ['sometimes', 'boolean'],
            ...$this->imageRules('front', true, ['file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', "max:{$maxKilobytes}", 'dimensions:min_width=1,min_height=1']),
            ...$this->imageRules('back', $this->input('document_type') === 'NATIONAL_ID', ['file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', "max:{$maxKilobytes}", 'dimensions:min_width=1,min_height=1']),
        ];
    }
}
