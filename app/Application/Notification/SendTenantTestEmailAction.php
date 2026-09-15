<?php

namespace App\Application\Notification;

use App\Application\Tenant\CompanyConfigurationAuthority;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Notification\Contracts\TestEmailSender;
use App\Domain\Notification\DTOs\EmailConnection;
use App\Domain\Notification\Exceptions\EmailDeliveryUnknown;
use App\Domain\Notification\Models\TenantEmailTestRequest;
use App\Domain\Notification\Services\TenantEmailPolicy;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Services\EmailNormalizer;
use App\Support\Errors\DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;

final readonly class SendTenantTestEmailAction
{
    public function __construct(private TestEmailSender $sender, private EmailNormalizer $emails, private AuditLogger $audit) {}

    public function execute(Tenant $tenant, string $requestId, #[\SensitiveParameter] string $recipient, AdminUser $actor): string
    {
        app(CompanyConfigurationAuthority::class)->assert($actor);
        $recipient = $this->emails->normalize($recipient);
        $recipientHash = hash_hmac('sha256', json_encode([$tenant->id, $recipient], JSON_THROW_ON_ERROR), (string) config('user-auth.otp_secret'));
        [$attempt, $connection] = DB::transaction(function () use ($tenant, $requestId, $recipientHash): array {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $previous = TenantEmailTestRequest::query()->where('tenant_id', $tenant->id)->where('request_id', $requestId)->lockForUpdate()->first();
            if ($previous) {
                if (! hash_equals($previous->recipient_hash, $recipientHash)) {
                    throw new DomainException('EMAIL_TEST_REQUEST_CONFLICT', 'This test request was already used for a different recipient.');
                }

                return [$previous, null];
            }
            $settings = app(TenantEmailPolicy::class)->settings($tenant->id);
            if (! $settings?->available()) {
                throw new DomainException('EMAIL_SETTINGS_INCOMPLETE', 'Save and enable the email configuration before sending a test.');
            }
            if (TenantEmailTestRequest::query()->where('tenant_id', $tenant->id)->where('configuration_version', $settings->configuration_version)
                ->where('recipient_hash', $recipientHash)->whereIn('status', ['PENDING', 'UNKNOWN'])->exists()) {
                throw new DomainException('EMAIL_TEST_UNCONFIRMED', 'A previous test is unconfirmed. Check the inbox and Proton Sent folder; it will not be resent automatically.');
            }
            // Durable limit survives cache resets. Stable request replay consumes no new attempt.
            if (TenantEmailTestRequest::query()->where('tenant_id', $tenant->id)->where('created_at', '>', now()->subMinute())->exists()
                || TenantEmailTestRequest::query()->where('tenant_id', $tenant->id)->where('created_at', '>', now()->subHour())->count() >= 10) {
                throw new DomainException('EMAIL_TEST_LIMIT', 'Please wait before sending another test email.', 429);
            }

            try {
                $connection = new EmailConnection($settings->from_address, $settings->from_name, $settings->smtp_token);
            } catch (DecryptException) {
                throw new DomainException('EMAIL_SETTINGS_INCOMPLETE', 'Save and enable the email configuration before sending a test.');
            }

            return [TenantEmailTestRequest::query()->create(['tenant_id' => $tenant->id, 'request_id' => $requestId,
                'configuration_version' => $settings->configuration_version, 'recipient_hash' => $recipientHash, 'status' => 'PENDING']), $connection];
        });
        if ($connection === null) {
            return $attempt->status === 'PENDING' ? 'UNKNOWN' : $attempt->status;
        }
        try {
            $this->sender->sendTest($tenant, $recipient, $connection);
            $status = 'ACCEPTED';
        } catch (EmailDeliveryUnknown) {
            $status = 'UNKNOWN';
        } catch (DomainException) {
            $status = 'REJECTED';
        }
        DB::transaction(function () use ($tenant, $attempt, $status, $actor, $requestId): void {
            TenantEmailTestRequest::query()->where('tenant_id', $tenant->id)->whereKey($attempt->id)->update(['status' => $status]);
            $this->audit->record($tenant->id, 'ADMIN', $actor->id, 'TENANT_EMAIL_TEST_FINISHED', 'tenant_email_test_requests', $attempt->id,
                after: ['result' => $status], requestId: $requestId);
        });

        return $status;
    }
}
