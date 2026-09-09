<?php

namespace App\Http\Requests;

use App\Domain\Kyc\Enums\KycReviewReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReviewKycApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason_code' => ['required', Rule::enum(KycReviewReason::class)],
            'review_message' => ['required', 'string', 'min:3', 'max:500', 'not_regex:/[<>]/'],
        ];
    }
}
