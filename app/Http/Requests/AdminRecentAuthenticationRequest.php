<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AdminRecentAuthenticationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['password' => ['required', 'string', 'max:255']];
    }
}
