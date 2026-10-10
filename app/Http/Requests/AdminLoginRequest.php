<?php

namespace App\Http\Requests;

use App\Domain\Admin\Services\AdminLoginAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class AdminLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => AdminLoginAccount::normalize((string) $this->input('email'))]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email' => AdminLoginAccount::rules(),
            'password' => ['required', 'string', 'max:1024'],
        ];
    }

    public function ensureIsNotRateLimited(string $surface): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($surface), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($surface));
        throw ValidationException::withMessages([
            'email' => "Too many sign-in attempts. Try again in {$seconds} seconds.",
        ])->status(429);
    }

    public function hitRateLimiter(string $surface): void
    {
        RateLimiter::hit($this->throttleKey($surface), 60);
    }

    public function clearRateLimiter(string $surface): void
    {
        RateLimiter::clear($this->throttleKey($surface));
    }

    private function throttleKey(string $surface): string
    {
        return hash('sha256', AdminLoginAccount::normalize((string) $this->input('email')).'|'.$this->ip().'|'.$surface);
    }
}
