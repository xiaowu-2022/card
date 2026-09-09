<?php

use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Models\Permission;
use App\Domain\Admin\Models\Role;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Tenant\Models\Tenant;

beforeEach(fn () => $this->seed());

it('authorizes platform owner only in platform scope', function (): void {
    $owner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $tenant = Tenant::query()->firstOrFail();
    $authorization = app(AuthorizationService::class);

    expect($authorization->allows($owner, ScopeType::Platform, null, 'tenant.manage'))->toBeTrue()
        ->and($authorization->allows($owner, ScopeType::Tenant, $tenant->id, 'tenant.manage'))->toBeFalse();
});

it('keeps support away from sensitive card permissions', function (): void {
    $support = Role::query()->where('name', 'SUPPORT')->firstOrFail();
    expect($support->permissions()->where('name', 'cards.reveal_sensitive')->exists())->toBeFalse();
});

it('does not define any forbidden money or history mutation permission', function (): void {
    $forbidden = ['wallet.balance.modify', 'wallet.balance.credit', 'wallet.balance.debit', 'security_deposit.modify', 'commission.modify', 'ledger.manual_create', 'ledger.edit', 'ledger.delete', 'card.status.force'];
    expect(Permission::query()->whereIn('name', $forbidden)->exists())->toBeFalse();
});

it('gives finance viewer read-only money permissions', function (): void {
    $names = Role::query()->where('name', 'FINANCE_VIEWER')->firstOrFail()->permissions()->pluck('name');
    expect($names)->toContain('wallet.read', 'ledger.read')->not->toContain('wallet.balance.modify');
});
