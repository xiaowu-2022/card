<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class AcceptAdminInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'confirmed', 'max:1024'],
        ];
    }

    public function ensureIdentityConfirmationIsNotRateLimited(string $rawToken, string $tenantId): void
    {
        if (! RateLimiter::tooManyAttempts($this->confirmationThrottleKey($rawToken, $tenantId), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->confirmationThrottleKey($rawToken, $tenantId));
        throw ValidationException::withMessages([
            'password' => "Too many identity confirmation attempts. Try again in {$seconds} seconds.",
        ])->status(429);
    }

    public function hitIdentityConfirmationRateLimiter(string $rawToken, string $tenantId): void
    {
        RateLimiter::hit($this->confirmationThrottleKey($rawToken, $tenantId), 60);
    }

    public function clearIdentityConfirmationRateLimiter(string $rawToken, string $tenantId): void
    {
        RateLimiter::clear($this->confirmationThrottleKey($rawToken, $tenantId));
    }

    private function confirmationThrottleKey(string $rawToken, string $tenantId): string
    {
        return 'admin-invitation-confirmation|'.$tenantId.'|'.hash('sha256', $rawToken).'|'.$this->ip();
    }
}
