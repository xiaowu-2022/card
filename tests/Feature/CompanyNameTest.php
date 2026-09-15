<?php

use App\Application\Tenant\RenameCompanyAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed();
    $this->company = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->owner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->url = 'http://admin.localhost/platform/tenants/'.$this->company->id.'/name';
    $this->configuration = 'http://admin.localhost/platform/tenants/'.$this->company->id.'/configuration/card-products';
});

it('renames only the selected company and atomically records the actual name change', function (): void {
    $before = $this->company->getAttributes();
    $tables = ['tenant_domains', 'tenant_branding', 'users', 'user_cards', 'wallets', 'ledger_accounts', 'ledger_entries', 'ledger_postings'];
    $snapshots = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy($table === 'tenant_branding' ? 'tenant_id' : 'id')->get()->toJson()]);
    $other = Tenant::query()->where('slug', 'tenant-b')->firstOrFail()->getAttributes();
    $this->actingAs($this->owner, 'platform_admin')->from($this->configuration)->put($this->url, ['name' => '  新公司 New Company  '])
        ->assertRedirect($this->configuration)->assertSessionHasNoErrors();
    expect($this->company->fresh()->name)->toBe('新公司 New Company');
    foreach ($before as $key => $value) {
        if (! in_array($key, ['name', 'updated_at'])) {
            expect($this->company->fresh()->getRawOriginal($key))->toBe($value);
        }
    }
    expect(Tenant::query()->where('slug', 'tenant-b')->firstOrFail()->getAttributes())->toBe($other);
    foreach ($snapshots as $table => $json) {
        expect(DB::table($table)->orderBy($table === 'tenant_branding' ? 'tenant_id' : 'id')->get()->toJson())->toBe($json);
    }
    $audit = AuditLog::query()->where('action', 'COMPANY_RENAMED')->sole();
    expect($audit->tenant_id)->toBe($this->company->id)->and($audit->actor_id)->toBe($this->owner->id)
        ->and($audit->before_data)->toBe(['name' => 'Tenant A'])->and($audit->after_data)->toBe(['name' => '新公司 New Company']);
    $this->get($this->configuration)->assertOk()->assertInertia(fn ($page) => $page
        ->where('configurationCompany.name', '新公司 New Company')->where('configurationCompany.slug', 'tenant-a'));
    $this->put($this->url, ['name' => '新公司 New Company'])->assertSessionHasNoErrors();
    expect(AuditLog::query()->where('action', 'COMPANY_RENAMED')->count())->toBe(1);
});

it('rejects invalid names and attempts to change company identity', function (array $data, string $field): void {
    $this->actingAs($this->owner, 'platform_admin')->putJson($this->url, $data)->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($this->company->fresh()->name)->toBe('Tenant A')->and($this->company->fresh()->slug)->toBe('tenant-a');
    expect(AuditLog::query()->where('action', 'COMPANY_RENAMED')->count())->toBe(0);
})->with([
    [['name' => '   '], 'name'],
    [['name' => str_repeat('中', 121)], 'name'],
    [['name' => ['invalid']], 'name'],
    [['name' => 'New', 'slug' => 'new-slug'], 'slug'],
    [['name' => 'New', 'tenant_id' => 'other'], 'tenant_id'],
    [['name' => 'New', 'hostname' => 'example.com'], 'hostname'],
    [['name' => 'New', 'brand_name' => 'Other brand'], 'brand_name'],
]);

it('requires active SaaS identity membership and company management permission', function (): void {
    $this->put($this->url, ['name' => 'New'])->assertRedirect('/platform/login');
    $companyOwner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $this->actingAs($companyOwner, 'platform_admin')->put($this->url, ['name' => 'New'])->assertForbidden();
    expect(fn () => app(RenameCompanyAction::class)->execute($this->company->id, 'New', $companyOwner))->toThrow(HttpException::class);
    $this->owner->update(['status' => 'SUSPENDED']);
    $this->actingAs($this->owner, 'platform_admin')->put($this->url, ['name' => 'New'])->assertForbidden();
    $this->owner->update(['status' => 'ACTIVE']);
    $membership = $this->owner->memberships()->where('scope_type', 'PLATFORM')->firstOrFail();
    $membership->update(['status' => 'SUSPENDED']);
    $this->actingAs($this->owner, 'platform_admin')->put($this->url, ['name' => 'New'])->assertForbidden();
    $membership->update(['status' => 'ACTIVE']);
    $role = $membership->role;
    $role->permissions()->detach($role->permissions()->where('name', 'tenant.manage')->value('permissions.id'));
    $this->actingAs($this->owner, 'platform_admin')->put($this->url, ['name' => 'New'])->assertForbidden();
    expect(fn () => app(RenameCompanyAction::class)->execute($this->company->id, 'New', $this->owner))->toThrow(HttpException::class);
    expect($this->company->fresh()->name)->toBe('Tenant A');
});
