<?php

use App\Application\Tenant\UpdateCompanyDepositSettingsAction;
use App\Application\Tenant\UpdateTenantBusinessSettingsAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed();
    $this->company = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->otherCompany = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $this->platformOwner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->companyOwner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $this->url = 'http://admin.localhost/platform/tenants/'.$this->company->id.'/deposit-settings';
});

it('saves distinct company deposit amounts and waiting days without moving money', function (): void {
    expect($this->company->businessSettings->security_deposit_refund_wait_days)->toBeNull();
    $this->actingAs($this->platformOwner, 'platform_admin')->put($this->url, [
        'required_security_deposit_amount' => '125.12345678', 'security_deposit_refund_wait_days' => '30',
    ])->assertRedirect('http://admin.localhost/platform/tenants/'.$this->company->id)->assertSessionHasNoErrors();
    app(UpdateCompanyDepositSettingsAction::class)->execute($this->otherCompany->id, '80', 7, $this->platformOwner);
    expect($this->company->businessSettings()->first()->required_security_deposit_amount)->toBe('125.12345678')
        ->and($this->company->businessSettings()->first()->security_deposit_refund_wait_days)->toBe(30)
        ->and($this->otherCompany->businessSettings()->first()->security_deposit_refund_wait_days)->toBe(7)
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and(DB::table('wallets')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'COMPANY_DEPOSIT_SETTINGS_UPDATED')->count())->toBe(2);
    $this->get('http://admin.localhost/platform/tenants/'.$this->company->id)->assertRedirect('http://admin.localhost/platform/tenants/'.$this->company->id.'/configuration/card-products');
    $this->get('http://admin.localhost/platform/tenants/'.$this->company->id.'/configuration/settings/business')->assertOk()
        ->assertInertia(fn ($page) => $page->where('settings.business.depositRefundWaitDays', 30));
    $this->actingAs($this->companyOwner, 'tenant_admin')->get('http://a.localhost/admin/settings/business')
        ->assertOk()->assertInertia(fn ($page) => $page->where('settings.business.depositRefundWaitDays', 30));
});

it('rejects company-admin deposit mutations at both HTTP and application boundaries', function (): void {
    $data = ['required_security_deposit_amount' => '5', 'required_security_deposit_asset' => 'USDT',
        'security_deposit_refund_wait_days' => 1, 'allow_wallet_topup' => true, 'allow_withdrawal' => true];
    $this->actingAs($this->companyOwner, 'tenant_admin')->post('http://a.localhost/admin/settings/business', $data)
        ->assertForbidden();
    expect(fn () => app(UpdateTenantBusinessSettingsAction::class)->execute($this->company, $data, $this->companyOwner))->toThrow(HttpException::class)
        ->and(fn () => app(UpdateCompanyDepositSettingsAction::class)->execute($this->company->id, '5', 1, $this->companyOwner))->toThrow(DomainException::class)
        ->and($this->company->businessSettings()->first()->security_deposit_refund_wait_days)->toBeNull();
    $this->actingAs($this->companyOwner, 'platform_admin')->put($this->url, $data)->assertForbidden();
});

it('returns to the same company business settings after saving from that page', function (): void {
    $businessUrl = 'http://admin.localhost/platform/tenants/'.$this->company->id.'/configuration/settings/business';
    $this->actingAs($this->platformOwner, 'platform_admin')->get($businessUrl)->assertOk()
        ->assertInertia(fn ($page) => $page->where('configurationCompany.id', $this->company->id)
            ->where('configurationReadOnly', false));
    $this->from($businessUrl)->put($this->url, [
        'required_security_deposit_amount' => '150',
        'security_deposit_refund_wait_days' => '7',
    ])->assertRedirect($businessUrl)->assertSessionHasNoErrors();
    expect($this->company->businessSettings()->first()->required_security_deposit_amount)->toBe('150.00000000')
        ->and($this->company->businessSettings()->first()->security_deposit_refund_wait_days)->toBe(7)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('rejects invalid waiting periods or money without changing configuration', function (array $overrides, string $field): void {
    $this->actingAs($this->platformOwner, 'platform_admin')->put($this->url, array_replace([
        'required_security_deposit_amount' => '100', 'security_deposit_refund_wait_days' => 30,
    ], $overrides))->assertSessionHasErrors($field);
    expect($this->company->businessSettings()->first()->security_deposit_refund_wait_days)->toBeNull();
})->with([
    [['security_deposit_refund_wait_days' => -1], 'security_deposit_refund_wait_days'],
    [['security_deposit_refund_wait_days' => 3651], 'security_deposit_refund_wait_days'],
    [['security_deposit_refund_wait_days' => '1.5'], 'security_deposit_refund_wait_days'],
    [['security_deposit_refund_wait_days' => null], 'security_deposit_refund_wait_days'],
    [['required_security_deposit_amount' => '-1'], 'required_security_deposit_amount'],
    [['required_security_deposit_amount' => '1.123456789'], 'required_security_deposit_amount'],
    [['required_security_deposit_amount' => 100], 'required_security_deposit_amount'],
    [['tenant_id' => 'spoofed'], 'tenant_id'],
    [['required_security_deposit_asset' => 'USD'], 'required_security_deposit_asset'],
]);

it('requires active platform membership and permission including direct calls', function (): void {
    $this->platformOwner->memberships()->delete();
    $this->actingAs($this->platformOwner, 'platform_admin')->put($this->url, [
        'required_security_deposit_amount' => '100', 'security_deposit_refund_wait_days' => 30,
    ])->assertForbidden();
    expect(fn () => app(UpdateCompanyDepositSettingsAction::class)->execute($this->company->id, '100', 30, $this->platformOwner))->toThrow(DomainException::class);
});

it('rejects a suspended platform administrator or a role without company-management permission', function (string $restriction): void {
    if ($restriction === 'admin') {
        $this->platformOwner->update(['status' => 'SUSPENDED']);
    } else {
        $role = $this->platformOwner->memberships()->firstOrFail()->role;
        $role->permissions()->detach($role->permissions()->where('name', 'tenant.manage')->value('permissions.id'));
    }
    expect(fn () => app(UpdateCompanyDepositSettingsAction::class)->execute($this->company->id, '100', 30, $this->platformOwner))->toThrow(DomainException::class);
})->with(['admin', 'permission']);

it('accepts explicit waiting-period boundaries without changing another company', function (int $days): void {
    app(UpdateCompanyDepositSettingsAction::class)->execute($this->company->id, '0', $days, $this->platformOwner);
    expect($this->company->businessSettings()->first()->security_deposit_refund_wait_days)->toBe($days)
        ->and($this->otherCompany->businessSettings()->first()->security_deposit_refund_wait_days)->toBeNull();
})->with([0, 3650]);

it('enforces the waiting-day range at the database boundary', function (): void {
    expect(fn () => DB::transaction(fn () => $this->company->businessSettings()->update(['security_deposit_refund_wait_days' => -1])))
        ->toThrow(QueryException::class);
});
