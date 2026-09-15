<?php

use App\Application\Admin\FinancialOperationQuery;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Str;

it('scopes manual financial history by company and resource without exposing audit payloads', function (): void {
    $this->seed();
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    $owner = AdminUser::query()->where('email', 'owner@platform.local')->sole();
    $tenantA = Tenant::query()->where('slug', 'tenant-a')->sole();
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->sole();
    $id = (string) Str::uuid();
    $audit = app(AuditLogger::class);
    $entry = $audit->record($tenantA->id, 'ADMIN', $owner->id, 'WITHDRAWAL_APPROVED', 'withdrawal_order', $id, null, ['internal_note' => 'not-for-public-dto']);
    $audit->record($tenantB->id, 'ADMIN', $owner->id, 'WITHDRAWAL_REJECTED', 'withdrawal_order', $id);
    $audit->record($tenantA->id, 'ADMIN', $owner->id, 'OTHER_RESOURCE', 'card_product', $id);
    $audit->record($tenantA->id, 'SYSTEM', null, 'SYSTEM_OPERATION', 'withdrawal_order', $id);
    $rows = app(FinancialOperationQuery::class)->forOrder($tenantA->id, 'withdrawal_order', $id);
    expect($rows)->toHaveCount(1)->and($rows[0]['operatorId'])->toBe($owner->id)
        ->and($rows[0]['operatedAt'])->toBe($entry->created_at->toIso8601String())
        ->and(json_encode($rows))->not->toContain('not-for-public-dto', 'after_data', 'WITHDRAWAL_REJECTED');
    $this->actingAs($owner, 'platform_admin')->get('http://admin.localhost/platform/financial-operations?company='.$tenantA->id.'&search='.$owner->id)->assertOk()
        ->assertInertia(fn ($page) => $page->has('operations.data', 1)->where('operations.data.0.operatorName', $owner->name));
    $companyOwner = AdminUser::query()->where('email', 'owner@a.localhost')->sole();
    $this->actingAs($companyOwner, 'platform_admin')->get('http://admin.localhost/platform/financial-operations')->assertForbidden();
});
