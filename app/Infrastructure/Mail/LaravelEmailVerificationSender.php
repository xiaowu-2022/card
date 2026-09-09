<?php

namespace App\Infrastructure\Mail;

use App\Domain\Notification\Contracts\EmailVerificationSender;
use App\Domain\Tenant\Models\Tenant;
use App\Mail\ExistingUserAccountMail;
use App\Mail\UserVerificationCodeMail;
use Illuminate\Support\Facades\Mail;

final class LaravelEmailVerificationSender implements EmailVerificationSender
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function sendVerificationCode(Tenant $tenant, string $destination, string $code): void
    {
        Mail::to($destination)->send(new UserVerificationCodeMail($tenant, $code));
    }

    public function sendExistingAccountNotice(Tenant $tenant, string $destination): void
    {
        Mail::to($destination)->send(new ExistingUserAccountMail($tenant));
    }
}
