<?php

namespace App\Http\Requests;

use App\Domain\User\Services\EmailNormalizer;
use App\Support\Errors\DomainException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class UserLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255', 'email'],
            'password' => ['required', 'string', 'max:1024'],
        ];
    }

    public function ensureIsNotRateLimited(string $tenantId): void
    {
        if (! RateLimiter::tooManyAttempts($this->key($tenantId), (int) config('user-auth.login_max_attempts'))) {
            return;
        }
        throw ValidationException::withMessages(['identifier' => 'Too many sign-in attempts. Try again later.'])->status(429);
    }

    public function hitRateLimiter(string $tenantId): void
    {
        RateLimiter::hit($this->key($tenantId), 60);
    }

    public function clearRateLimiter(string $tenantId): void
    {
        RateLimiter::clear($this->key($tenantId));
    }

    private function key(string $tenantId): string
    {
        $identifier = (string) $this->input('identifier');
        try {
            $normalized = app(EmailNormalizer::class)->normalize($identifier);
        } catch (DomainException) {
            $normalized = strtolower(trim($identifier));
        }

        return 'user-login|'.$tenantId.'|'.hash('sha256', $normalized).'|'.$this->ip();
    }
}
