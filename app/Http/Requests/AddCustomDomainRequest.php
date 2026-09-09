<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AddCustomDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['hostname' => ['required', 'string', 'max:253']];
    }
}
