<?php

use App\Application\Promotion\PromotionMembershipAction;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshIsolatedDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshIsolatedDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Architecture');

function kycTestImage(string $name = 'identity.png'): UploadedFile
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);

    return UploadedFile::fake()->createWithContent($name, $png ?: 'invalid');
}

function registrationTestInvitation(string $slug = 'tenant-a'): string
{
    $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();

    return app(PromotionMembershipAction::class)->companyInvitation($tenant->id)->invitation_code;
}

/** Explicit pre-upgrade fixtures for immutable historical USD accounting tests. */
function legacyUsdAccountingFixtures(): void
{
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
    DB::table('tenants')->update(['default_asset' => 'USD']);
    DB::table('tenant_business_settings')->update(['required_security_deposit_asset' => 'USD']);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
}

function notificationProfileUrl(string $channel, Tenant $tenant): string
{
    $profile = DB::table('tenant_notification_profiles')->where('tenant_id', $tenant->id)->value($channel.'_profile_id');

    return 'http://admin.localhost/platform/settings/'.$channel.($profile ? '/'.$profile : '');
}
function bindNotificationTestProfile(Tenant $tenant, string $channel, string $profileId): void
{
    DB::table('tenant_notification_profiles')->upsert([['tenant_id' => $tenant->id, $channel.'_profile_id' => $profileId, 'created_at' => now(), 'updated_at' => now()]], ['tenant_id'], [$channel.'_profile_id', 'updated_at']);
}
