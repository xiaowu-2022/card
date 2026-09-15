<?php

namespace App\Infrastructure\Mail;

use App\Domain\Notification\Contracts\CompanyEmailTransport;
use App\Domain\Notification\Contracts\EmailVerificationSender;
use App\Domain\Notification\Contracts\TestEmailSender;
use App\Domain\Notification\DTOs\EmailConnection;
use App\Domain\Notification\Services\TenantEmailPolicy;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Errors\DomainException;
use Illuminate\Contracts\Encryption\DecryptException;

final readonly class ProtonEmailVerificationSender implements EmailVerificationSender, TestEmailSender
{
    public function __construct(private TenantEmailPolicy $policy, private CompanyEmailTransport $transport) {}

    public function isAvailable(Tenant $tenant): bool
    {
        return $this->policy->settings($tenant->id)?->available() ?? false;
    }

    public function sendVerificationCode(Tenant $tenant, #[\SensitiveParameter] string $destination, #[\SensitiveParameter] string $code): void
    {
        $this->transport->send($this->connection($tenant), $destination, 'Your verification code', view('mail.user-verification-code', ['tenant' => $tenant, 'code' => $code])->render());
    }

    public function sendExistingAccountNotice(Tenant $tenant, #[\SensitiveParameter] string $destination): void
    {
        $this->transport->send($this->connection($tenant), $destination, 'A sign-in reminder', view('mail.existing-user-account', ['tenant' => $tenant])->render());
    }

    public function sendTest(Tenant $tenant, #[\SensitiveParameter] string $destination, #[\SensitiveParameter] EmailConnection $connection): void
    {
        $this->transport->send($connection, $destination, 'SMTP connection test / SMTP 测试', '<p>Your company SMTP settings can send email.</p><p>本公司的 SMTP 配置可以发送邮件。</p><p>This is a test message, not a verification code. 此为测试邮件，并非验证码。</p>');
    }

    private function connection(Tenant $tenant): EmailConnection
    {
        $settings = $this->policy->settings($tenant->id);
        if (! $settings?->available()) {
            throw new DomainException('EMAIL_TRANSPORT_UNAVAILABLE', 'Email verification is not available for this company.', 503);
        }
        try {
            return new EmailConnection($settings->from_address, $settings->from_name, $settings->smtp_token);
        } catch (DecryptException) {
            throw new DomainException('EMAIL_TRANSPORT_UNAVAILABLE', 'Email verification is not available for this company.', 503);
        }
    }
}
