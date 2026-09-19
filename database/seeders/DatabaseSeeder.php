<?php

namespace Database\Seeders;

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\MembershipStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminMembership;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Models\Permission;
use App\Domain\Admin\Models\Role;
use App\Domain\CardProduct\Enums\CardProductStatus;
use App\Domain\CardProduct\Enums\TenantCardProductStatus;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProduct\Models\TenantCardProductConfig;
use App\Domain\Tenant\Enums\TenantDomainStatus;
use App\Domain\Tenant\Enums\TenantDomainType;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantBranding;
use App\Domain\Tenant\Models\TenantBusinessSetting;
use App\Domain\Tenant\Models\TenantDomain;
use App\Domain\Tenant\Models\TenantKycSetting;
use App\Domain\Tenant\Models\TenantLocale;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserPreference;
use App\Domain\User\Models\UserProfile;
use App\Infrastructure\Providers\Card\LocalCardSimulation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('testing')) {
            PlatformKycSetting::current()->update(['enabled' => true, 'max_accounts_per_identity' => 1, 'review_mode' => 'MANUAL']);
        }

        $permissions = collect([
            'promotion_refunds.review', 'tenant.read', 'tenant.manage', 'users.read', 'users.suspend', 'wallet_topups.verify', 'wallet_topups.confirm',
            'kyc.read', 'kyc.review', 'kyc.document.view', 'wallet.read', 'ledger.read', 'wallet_topups.read', 'withdrawals.read', 'withdrawals.review',
            'cards.read', 'cards.reveal_sensitive', 'card_load.process',
            'provider_operation.read', 'provider_operation.retry', 'provider_credentials.manage',
            'card_product.read', 'card_product.manage', 'card_limit.manage', 'card_provider_reference.manage',
            'tenant_settings.manage', 'audit.read',
            'admin_team.read', 'admin_team.manage', 'tenant.activate',
        ])->mapWithKeys(fn (string $name) => [$name => Permission::query()->firstOrCreate(['name' => $name])]);

        $roles = [
            'PLATFORM_OWNER' => [ScopeType::Platform, $permissions->keys()->all()],
            'PLATFORM_ADMIN' => [ScopeType::Platform, ['tenant.read', 'tenant.manage', 'admin_team.read', 'admin_team.manage', 'users.read', 'kyc.read', 'wallet.read', 'ledger.read', 'wallet_topups.read', 'wallet_topups.verify', 'wallet_topups.confirm', 'withdrawals.read', 'cards.read', 'card_product.read', 'card_product.manage', 'provider_operation.read', 'audit.read']],
            'PLATFORM_AUDITOR' => [ScopeType::Platform, ['tenant.read', 'users.read', 'kyc.read', 'wallet.read', 'ledger.read', 'withdrawals.read', 'cards.read', 'provider_operation.read', 'audit.read']],
            'TENANT_OWNER' => [ScopeType::Tenant, ['admin_team.read', 'admin_team.manage', 'tenant.activate', 'users.read', 'users.suspend', 'kyc.read', 'kyc.review', 'kyc.document.view', 'wallet.read', 'ledger.read', 'wallet_topups.read', 'withdrawals.read', 'withdrawals.review', 'cards.read', 'cards.reveal_sensitive', 'card_load.process', 'card_product.read', 'card_product.manage', 'card_limit.manage', 'tenant_settings.manage', 'audit.read']],
            'TENANT_ADMIN' => [ScopeType::Tenant, ['users.read', 'users.suspend', 'kyc.read', 'wallet.read', 'ledger.read', 'wallet_topups.read', 'withdrawals.read', 'withdrawals.review', 'cards.read', 'card_product.read', 'card_product.manage', 'tenant_settings.manage', 'audit.read']],
            'KYC_REVIEWER' => [ScopeType::Tenant, ['users.read', 'kyc.read', 'kyc.review', 'kyc.document.view']],
            'CARD_OPERATOR' => [ScopeType::Tenant, ['users.read', 'wallet.read', 'cards.read', 'cards.reveal_sensitive', 'card_load.process', 'provider_operation.read', 'provider_operation.retry']],
            'FINANCE_VIEWER' => [ScopeType::Tenant, ['users.read', 'wallet.read', 'ledger.read', 'wallet_topups.read', 'withdrawals.read']],
            'SUPPORT' => [ScopeType::Tenant, ['users.read', 'kyc.read', 'cards.read']],
        ];

        $permissions->put('support.manage', Permission::query()->firstOrCreate(['name' => 'support.manage']));
        $roles['PLATFORM_ADMIN'][1][] = 'card_provider_reference.manage';
        $roles['PLATFORM_ADMIN'][1][] = 'promotion_refunds.review';
        foreach (['TENANT_OWNER', 'TENANT_ADMIN', 'SUPPORT'] as $supportRole) {
            $roles[$supportRole][1][] = 'support.manage';
        }

        $roleModels = collect($roles)->mapWithKeys(function (array $definition, string $name) use ($permissions): array {
            [$scope, $permissionNames] = $definition;
            $role = Role::query()->firstOrCreate(['name' => $name], ['scope_type' => $scope]);
            $role->permissions()->sync(collect($permissionNames)->map(fn (string $permission) => $permissions[$permission]->id));

            return [$name => $role];
        });

        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $tenantA = $this->tenant('Tenant A', 'tenant-a', 'a.localhost', '#39AD8D');
        $tenantB = $this->tenant('Tenant B', 'tenant-b', 'b.localhost', '#6941C6');

        $platformOwner = AdminUser::query()->firstOrCreate(['email' => 'owner@platform.local'], ['name' => 'Platform Owner', 'password' => $this->seedPasswordHash(), 'status' => AdminUserStatus::Active]);
        $ownerA = AdminUser::query()->firstOrCreate(['email' => 'owner@a.localhost'], ['name' => 'Tenant Owner A', 'password' => $this->seedPasswordHash(), 'status' => AdminUserStatus::Active]);
        $ownerB = AdminUser::query()->firstOrCreate(['email' => 'owner@b.localhost'], ['name' => 'Tenant Owner B', 'password' => $this->seedPasswordHash(), 'status' => AdminUserStatus::Active]);

        AdminMembership::query()->firstOrCreate(
            ['admin_user_id' => $platformOwner->id, 'scope_type' => ScopeType::Platform, 'scope_id' => null],
            ['role_id' => $roleModels['PLATFORM_OWNER']->id, 'status' => MembershipStatus::Active],
        );
        AdminMembership::query()->firstOrCreate(
            ['admin_user_id' => $ownerA->id, 'scope_type' => ScopeType::Tenant, 'scope_id' => $tenantA->id],
            ['role_id' => $roleModels['TENANT_OWNER']->id, 'status' => MembershipStatus::Active],
        );

        $this->user($tenantA, 'user@a.localhost', 'Tenant A User');
        $this->user($tenantB, 'user@b.localhost', 'Tenant B User');
        AdminMembership::query()->firstOrCreate(
            ['admin_user_id' => $ownerB->id, 'scope_type' => ScopeType::Tenant, 'scope_id' => $tenantB->id],
            ['role_id' => $roleModels['TENANT_OWNER']->id, 'status' => MembershipStatus::Active],
        );

        $product = CardProduct::query()->where('provider', 'PHOTONPAY')
            ->where('provider_product_ref', 'DEMO-MILLE-REGULAR-54493747')->first() ?? new CardProduct;
        if (! $product->exists) {
            $product->forceFill([
                'provider' => 'PHOTONPAY',
                'provider_product_ref' => 'DEMO-MILLE-REGULAR-54493747',
                'name' => 'Mille Card',
                'card_currency' => 'USD',
                'card_type' => 'REGULAR',
                'minimum_initial_load' => '20.00000000',
                'opening_fee' => '5.00000000',
                'minimum_reload' => '20.00000000',
                'status' => CardProductStatus::Active,
            ])->save();
        }
        $this->cardProductConfig($tenantA, $product, 'Mille Card', '5.00000000');
        $this->cardProductConfig($tenantB, $product, 'Mille Card', '8.00000000');
    }

    private function tenant(string $name, string $slug, string $hostname, string $primaryColor): Tenant
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], [
            'name' => $name,
            'status' => TenantStatus::Active,
            'default_locale' => 'en',
            'timezone' => 'Asia/Kuala_Lumpur',
            'default_asset' => 'USDT',
            'activated_at' => now(),
        ]);

        TenantDomain::query()->firstOrCreate(['hostname' => $hostname], [
            'tenant_id' => $tenant->id,
            'domain_type' => TenantDomainType::SystemSubdomain,
            'status' => TenantDomainStatus::Active,
            'is_primary' => true,
            'verified_at' => now(),
        ]);
        TenantBranding::query()->firstOrCreate(['tenant_id' => $tenant->id], ['brand_name' => $name.' Cards', 'primary_color' => $primaryColor, 'support_email' => 'support@'.$hostname]);
        TenantLocale::query()->firstOrCreate(['tenant_id' => $tenant->id, 'locale' => 'en'], ['enabled' => true, 'is_default' => true]);
        if ($tenant->wasRecentlyCreated && LocalCardSimulation::enabled()) {
            // Fresh isolated demos expose every supported consumer language. Reseeding must
            // not override language choices already made in an existing company's settings.
            foreach (config('tenancy.supported_locales') as $locale) {
                TenantLocale::query()->firstOrCreate(['tenant_id' => $tenant->id, 'locale' => $locale], [
                    'enabled' => true, 'is_default' => $locale === $tenant->default_locale,
                ]);
            }
        }
        TenantBusinessSetting::query()->firstOrCreate(['tenant_id' => $tenant->id], ['withdrawal_fee_percent' => '0', 'required_security_deposit_amount' => '100.00000000', 'required_security_deposit_asset' => $tenant->default_asset, 'allow_wallet_topup' => true, 'allow_withdrawal' => true]);
        TenantKycSetting::query()->firstOrCreate(['tenant_id' => $tenant->id], ['enabled' => true, 'max_accounts_per_identity' => 1, 'review_mode' => 'MANUAL']);

        return $tenant;
    }

    private function seedPasswordHash(): string
    {
        return Hash::make(app()->environment('local') ? '123456' : 'local-password');
    }

    private function user(Tenant $tenant, string $email, string $displayName): void
    {
        $user = User::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'email' => $email],
            ['phone' => null, 'password_hash' => $this->seedPasswordHash(), 'status' => UserStatus::Active, 'email_verified_at' => now()],
        );
        UserProfile::query()->firstOrCreate(['user_id' => $user->id], ['tenant_id' => $tenant->id, 'display_name' => $displayName]);
        UserPreference::query()->firstOrCreate(['user_id' => $user->id], ['tenant_id' => $tenant->id, 'locale' => $tenant->default_locale]);
    }

    private function cardProductConfig(Tenant $tenant, CardProduct $product, string $displayName, string $openingFee): void
    {
        $config = TenantCardProductConfig::query()->where('tenant_id', $tenant->id)
            ->where('card_product_id', $product->id)->first() ?? new TenantCardProductConfig;
        if (! $config->exists) {
            $config->forceFill([
                'tenant_id' => $tenant->id,
                'card_product_id' => $product->id,
                'display_name' => $displayName,
                'opening_fee' => $openingFee,
                'max_cards_per_user' => 3,
                'status' => TenantCardProductStatus::Active,
                'sort_order' => 10,
            ])->save();
        }
    }
}
