<?php

use App\Application\CardProduct\ConfigureTenantCardProductAction;
use App\Application\Tenant\CreateTenantAction;
use App\Application\Tenant\UpdateTenantBusinessSettingsAction;
use App\Domain\Admin\Models\AdminMembership;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProduct\Models\TenantCardProductConfig;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->seed();
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    Mail::fake();
    $this->owner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->companyOwner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $this->company = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->otherCompany = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $this->product = CardProduct::query()->firstOrFail();
    $this->base = 'http://admin.localhost/platform/tenants/'.$this->company->id.'/configuration';
});

it('blocks every company configuration write including stale forms', function (): void {
    $this->actingAs($this->companyOwner, 'tenant_admin');
    foreach (['settings/branding', 'settings/locales', 'settings/business', 'settings/kyc', 'settings/sms', 'settings/email', 'settings/email/test', 'settings/articles/terms/en', 'promotion', 'onboarding/activate', 'team/administrators', 'team/invitations', 'team/invitations/'.Str::uuid().'/resend', 'team/invitations/'.Str::uuid().'/cancel'] as $path) {
        $this->postJson('http://a.localhost/admin/'.$path, [])->assertForbidden();
    }
    $this->putJson('http://a.localhost/admin/card-products/'.$this->product->id, [])->assertForbidden();
    $data = ['display_name' => 'Unauthorized', 'max_cards_per_user' => 2, 'status' => 'ACTIVE', 'sort_order' => 1];
    expect(fn () => app(ConfigureTenantCardProductAction::class)->execute($this->company->id, $this->product->id, $data, $this->companyOwner))->toThrow(HttpException::class);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

it('keeps all company configuration views read only and makes the same views available to SaaS', function (): void {
    foreach (['card-products', 'settings/branding', 'settings/locales', 'settings/business', 'settings/kyc', 'settings/articles', 'settings/sms', 'settings/email', 'promotion', 'team', 'onboarding'] as $path) {
        $this->actingAs($this->companyOwner, 'tenant_admin')->get('http://a.localhost/admin/'.$path)->assertOk()
            ->assertInertia(fn ($page) => $page->where('configurationReadOnly', true));
        if ($path === 'settings/kyc') {
            $this->actingAs($this->owner, 'platform_admin')->get($this->base.'/'.$path)->assertRedirect('/platform/settings/kyc');

            continue;
        }
        $this->actingAs($this->owner, 'platform_admin')->get($this->base.'/'.$path)->assertOk()
            ->assertInertia(fn ($page) => $page->where('configurationCompany.id', $this->company->id)
                ->where('configurationReadOnly', false)->where('configurationBase', '/platform/tenants/'.$this->company->id.'/configuration'));
    }
});

it('lets SaaS configure only the company in its route and preserves historical prices and funds', function (): void {
    $other = TenantCardProductConfig::query()->where('tenant_id', $this->otherCompany->id)->where('card_product_id', $this->product->id)->firstOrFail();
    $before = $other->toArray();
    $entries = LedgerEntry::query()->count();
    $this->actingAs($this->owner, 'platform_admin')->put($this->base.'/card-products/'.$this->product->id, [
        'display_name' => 'SaaS configured', 'max_cards_per_user' => 2, 'status' => 'ACTIVE', 'sort_order' => 2,
        'tenant_id' => $this->otherCompany->id,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(TenantCardProductConfig::query()->where('tenant_id', $this->company->id)->where('card_product_id', $this->product->id)->value('display_name'))->toBe('SaaS configured')
        ->and($other->fresh()->toArray())->toBe($before)
        ->and(LedgerEntry::query()->count())->toBe($entries);
});

it('requires an active platform membership even for a company owner', function (): void {
    $this->actingAs($this->companyOwner, 'platform_admin')->get($this->base.'/card-products')->assertForbidden();
    $this->actingAs($this->owner, 'platform_admin');
    AdminMembership::query()->where('admin_user_id', $this->owner->id)->where('scope_type', 'PLATFORM')->update(['status' => 'SUSPENDED']);
    $this->get($this->base.'/card-products')->assertForbidden();
});

it('saves SaaS policy and article settings using the selected tenant without altering other companies', function (): void {
    $this->actingAs($this->owner, 'platform_admin');
    $this->post($this->base.'/settings/kyc', ['enabled' => true, 'max_accounts_per_identity' => 4, 'review_mode' => 'MANUAL'])->assertForbidden();
    $this->post($this->base.'/settings/articles/terms/en', ['body' => 'Platform managed company terms'])->assertRedirect()->assertSessionHasNoErrors();
    $this->postJson($this->base.'/promotion', ['action' => 'level', 'rank' => 999, 'name' => 'Retired', 'reward' => '0'])->assertUnprocessable();
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $this->company->id)->where('rank', 1)->first();
    $this->post($this->base.'/paid-promotion/levels/'.$level->id, ['fee' => '1100', 'percent' => 30, 'reward' => 50, 'target' => 100, 'revision' => $level->revision, 'enabled' => true, 'current_password' => 'local-password'])->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('paid_promotion_levels')->where('tenant_id', $this->company->id)->where('rank', 1)->value('fee'))->toBe('1100.00000000')
        ->and(DB::table('paid_promotion_levels')->where('tenant_id', $this->otherCompany->id)->where('rank', 1)->value('fee'))->toBe('1000.00000000');
});

it('saves fees without operation toggles and prevents stale forms from disabling them', function (): void {
    $this->actingAs($this->owner, 'platform_admin');
    $entries = LedgerEntry::query()->count();
    $this->post($this->base.'/settings/business', ['withdrawal_fee_percent' => '1.25'])->assertRedirect()->assertSessionHasNoErrors();
    $settings = $this->company->businessSettings()->firstOrFail();
    expect($settings->allow_wallet_topup)->toBeTrue()->and($settings->allow_withdrawal)->toBeTrue()
        ->and($settings->withdrawal_fee_percent)->toBe('1.25000000');
    $this->post($this->base.'/settings/business', ['allow_wallet_topup' => false, 'allow_withdrawal' => false, 'withdrawal_fee_percent' => '9'])->assertSessionHasErrors(['allow_wallet_topup', 'allow_withdrawal']);
    expect($settings->fresh()->withdrawal_fee_percent)->toBe('1.25000000');
    app(UpdateTenantBusinessSettingsAction::class)->execute($this->company, ['allow_wallet_topup' => false, 'allow_withdrawal' => false], $this->owner);
    expect($settings->fresh()->allow_wallet_topup)->toBeTrue()->and($settings->fresh()->allow_withdrawal)->toBeTrue()
        ->and(LedgerEntry::query()->count())->toBe($entries);
});

it('enables existing companies without changing fee or deposit configuration and enables new companies', function (): void {
    $this->company->businessSettings()->update(['allow_wallet_topup' => false, 'allow_withdrawal' => false, 'withdrawal_fee_percent' => '2.50']);
    $before = $this->company->businessSettings()->firstOrFail()->only(['required_security_deposit_amount', 'required_security_deposit_asset', 'security_deposit_refund_wait_days', 'withdrawal_fee_percent']);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
    $migration = require database_path('migrations/2026_09_14_001100_enable_company_wallet_operations.php');
    $migration->up();
    foreach (Tenant::query()->get() as $company) {
        expect($company->businessSettings->allow_wallet_topup)->toBeTrue()->and($company->businessSettings->allow_withdrawal)->toBeTrue();
    }
    expect($this->company->businessSettings()->firstOrFail()->only(array_keys($before)))->toBe($before);
    $created = app(CreateTenantAction::class)->execute(['name' => 'Enabled Company', 'slug' => 'enabled-company', 'owner_email' => 'owner@enabled.test', 'default_locale' => 'en', 'timezone' => 'UTC', 'default_asset' => 'USDT'], $this->owner);
    expect($created->tenant->businessSettings->allow_wallet_topup)->toBeTrue()->and($created->tenant->businessSettings->allow_withdrawal)->toBeTrue();
    Http::assertNothingSent();
});

it('saves deposit amount refund wait and withdrawal fee together for the selected company', function (): void {
    $beforeOther = $this->otherCompany->businessSettings()->firstOrFail()->toArray();
    $entries = LedgerEntry::query()->count();
    $this->actingAs($this->owner, 'platform_admin')->post($this->base.'/settings/business', [
        'required_security_deposit_amount' => '125.50',
        'security_deposit_refund_wait_days' => '15',
        'withdrawal_fee_percent' => '1.25',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $settings = $this->company->businessSettings()->firstOrFail();
    expect($settings->required_security_deposit_amount)->toBe('125.50000000')
        ->and($settings->security_deposit_refund_wait_days)->toBe(15)
        ->and($settings->withdrawal_fee_percent)->toBe('1.25000000')
        ->and($this->otherCompany->businessSettings()->firstOrFail()->toArray())->toBe($beforeOther)
        ->and(LedgerEntry::query()->count())->toBe($entries);
    $audit = AuditLog::query()->where('action', 'TENANT_BUSINESS_SETTINGS_UPDATED')->where('tenant_id', $this->company->id)->sole();
    expect($audit->after_data['security_deposit_refund_wait_days'])->toBe(15)
        ->and($audit->after_data['required_security_deposit_amount'])->toBe('125.50000000')
        ->and($audit->after_data['withdrawal_fee_percent'])->toBe('1.25000000')
        ->and($audit->actor_id)->toBe($this->owner->id)->and($audit->created_at)->not->toBeNull();
});

it('does not partially save combined business settings when any field is invalid', function (array $invalid, string $field): void {
    $before = $this->company->businessSettings()->firstOrFail()->toArray();
    $this->actingAs($this->owner, 'platform_admin')->post($this->base.'/settings/business', [
        'required_security_deposit_amount' => '200', 'security_deposit_refund_wait_days' => '15', 'withdrawal_fee_percent' => '2.50', ...$invalid,
    ])->assertSessionHasErrors($field);
    expect($this->company->businessSettings()->firstOrFail()->toArray())->toBe($before);
})->with([
    [['required_security_deposit_amount' => '-1'], 'required_security_deposit_amount'],
    [['security_deposit_refund_wait_days' => '3651'], 'security_deposit_refund_wait_days'],
    [['withdrawal_fee_percent' => '100'], 'withdrawal_fee_percent'],
    [['tenant_id' => '00000000-0000-4000-8000-000000000001'], 'tenant_id'],
    [['required_security_deposit_asset' => 'USD'], 'required_security_deposit_asset'],
]);

it('keeps an unconfigured refund wait null when saving the combined form', function (): void {
    $this->actingAs($this->owner, 'platform_admin')->post($this->base.'/settings/business', [
        'required_security_deposit_amount' => '100', 'security_deposit_refund_wait_days' => '', 'withdrawal_fee_percent' => '0',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->company->businessSettings()->firstOrFail()->security_deposit_refund_wait_days)->toBeNull();
});
