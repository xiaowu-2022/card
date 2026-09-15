<?php

namespace App\Http\Requests;

use App\Domain\User\Enums\RegistrationChannel;
use App\Domain\User\Services\EmailNormalizer;
use App\Domain\User\Services\PhoneNormalizer;
use App\Support\Errors\DomainException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CreateRegistrationChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::in(['EMAIL', 'PHONE'])],
            'destination' => ['required', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'size:2'],
            // Legacy values are accepted only when the tenant-scoped alias resolves.
            'invitation_code' => ['required', 'string', 'regex:/^(?:[0-9]{6}|[a-fA-F0-9]{24})$/D'],
        ];
    }

    public function ensureIsNotRateLimited(string $tenantId): void
    {
        foreach ($this->rateLimitKeys($tenantId) as [$key, $limit]) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                throw ValidationException::withMessages(['destination' => 'Too many verification requests. Try again later.'])->status(429);
            }
        }
    }

    public function hitRateLimiters(string $tenantId): void
    {
        foreach ($this->rateLimitKeys($tenantId) as [$key]) {
            RateLimiter::hit($key, 3600);
        }
    }

    private function rateLimitKeys(string $tenantId): array
    {
        try {
            $channel = RegistrationChannel::from((string) $this->input('channel'));
            $destination = $channel === RegistrationChannel::Email
                ? app(EmailNormalizer::class)->normalize((string) $this->input('destination'))
                : app(PhoneNormalizer::class)->normalize((string) $this->input('destination'), $this->input('region'));
        } catch (DomainException|\ValueError) {
            $destination = strtolower(trim((string) $this->input('destination')));
        }
        $destinationHash = hash('sha256', $destination);
        $limit = (int) config('user-auth.send_limit_per_hour');

        return [
            ["registration-send|destination|{$tenantId}|{$destinationHash}", $limit],
            ["registration-send|ip|{$tenantId}|{$this->ip()}", $limit * 3],
        ];
    }
}
