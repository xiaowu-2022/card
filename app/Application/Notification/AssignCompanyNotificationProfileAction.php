<?php

namespace App\Application\Notification;

use App\Application\Tenant\CompanyConfigurationAuthority;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Notification\Models\PlatformEmailProfile;
use App\Domain\Notification\Models\PlatformSmsProfile;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class AssignCompanyNotificationProfileAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(string $tenantId, string $channel, ?string $profileId, AdminUser $actor, ?string $requestId = null): void
    {
        app(CompanyConfigurationAuthority::class)->assert($actor);
        abort_unless(in_array($channel, ['sms', 'email'], true), 404);
        DB::transaction(function () use ($tenantId, $channel, $profileId, $actor, $requestId): void {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            app(CompanyConfigurationAuthority::class)->assert($actor);
            $column = $channel.'_profile_id';
            if ($profileId !== null) {
                $model = $channel === 'sms' ? PlatformSmsProfile::class : PlatformEmailProfile::class;
                $profile = $model::query()->whereKey($profileId)->lockForUpdate()->firstOrFail();
                if (! $profile->available()) {
                    throw new DomainException('NOTIFICATION_PROFILE_UNAVAILABLE', 'Choose an enabled and configured profile.');
                }
            }
            $before = DB::table('tenant_notification_profiles')->where('tenant_id', $tenantId)->value($column);
            DB::table('tenant_notification_profiles')->upsert([
                ['tenant_id' => $tenantId, $column => $profileId, 'created_at' => now(), 'updated_at' => now()],
            ], ['tenant_id'], [$column, 'updated_at']);
            $this->audit->record($tenantId, 'ADMIN', $actor->id, 'COMPANY_NOTIFICATION_PROFILE_ASSIGNED', 'tenant_notification_profiles', $tenantId,
                ['channel' => $channel, 'profile_id' => $before], ['channel' => $channel, 'profile_id' => $profileId], $requestId);
        });
    }
}
