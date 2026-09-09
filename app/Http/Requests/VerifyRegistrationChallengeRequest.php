<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class VerifyRegistrationChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'digits:6']];
    }

    public function ensureIsNotRateLimited(string $tenantId, string $challengeId): void
    {
        if (! RateLimiter::tooManyAttempts($this->key($tenantId, $challengeId), (int) config('user-auth.verify_limit_per_minute'))) {
            return;
        }

        throw ValidationException::withMessages(['code' => 'Too many verification attempts. Request a new code.'])->status(429);
    }

    public function hitRateLimiter(string $tenantId, string $challengeId): void
    {
        RateLimiter::hit($this->key($tenantId, $challengeId), 60);
    }

    private function key(string $tenantId, string $challengeId): string
    {
        return "registration-verify|{$tenantId}|{$challengeId}|{$this->ip()}";
    }
}
