<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    $this->owner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->companyOwner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $this->company = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->otherCompany = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
});

it('opens all-company record lists without requiring selection', function (): void {
    $this->actingAs($this->owner, 'platform_admin');
    foreach (['users' => 'Users', 'kyc' => 'Kyc', 'wallets' => 'Wallets', 'topups' => 'Topups', 'cards' => 'Cards'] as $section => $component) {
        $this->get("http://admin.localhost/platform/{$section}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('platform/'.$component)->has('companies', 2)->missing('filters.company'));
    }
});

it('opens the empty card provider page only with active platform read permission', function (): void {
    $url = 'http://admin.localhost/platform/card-providers';
    $this->actingAs($this->owner, 'platform_admin')->get($url)->assertOk()
        ->assertInertia(fn ($page) => $page->component('platform/CardProviders')->missing('credentials')->has('providers.data', 0));
    $this->actingAs($this->companyOwner, 'platform_admin')->get($url)->assertForbidden();
    $permission = DB::table('permissions')->where('name', 'provider_operation.read')->value('id');
    DB::table('role_permissions')->where('permission_id', $permission)->delete();
    $this->actingAs($this->owner->fresh(), 'platform_admin')->get($url)->assertForbidden();
    Http::assertNothingSent();
});

it('shows only the selected company identity metadata and wallet accounts without changing money', function (): void {
    $fixtures = [];
    foreach ([$this->company, $this->otherCompany] as $company) {
        $user = User::query()->where('tenant_id', $company->id)->firstOrFail();
        $kyc = app(SubmitKycApplicationAction::class)->execute(
            $company, $user, 'MY', 'PLATFORM-'.$user->id, kycTestImage(), kycTestImage('back.png'),
        );
        $reviewer = AdminUser::query()->where('email', $company->id === $this->company->id ? 'owner@a.localhost' : 'owner@b.localhost')->firstOrFail();
        app(ApproveKycAction::class)->execute($company->id, $kyc->id, $reviewer);
        $wallet = app(ActivateUserWalletAction::class)->execute($company->id, $user->id)->wallet;
        $fixtures[$company->id] = [$user->fresh(), $kyc, $wallet];
    }
    [$user, $kyc, $wallet] = $fixtures[$this->company->id];
    $entriesBefore = LedgerEntry::query()->count();
    $this->actingAs($this->owner, 'platform_admin');
    $base = 'http://admin.localhost/platform';
    foreach (['kyc' => 'applications', 'wallets' => 'wallets'] as $section => $prop) {
        $this->get($base.'/'.$section)->assertOk()->assertInertia(fn ($page) => $page->has($prop.'.data', 2));
    }
    $this->get($base.'/kyc?company='.$this->company->id.'&status=APPROVED')->assertOk()->assertInertia(fn ($page) => $page
        ->component('platform/Kyc')->has('applications.data', 1)
        ->where('applications.data.0.companyId', $this->company->id)
        ->where('applications.data.0.id', $kyc->id)
        ->missing('applications.data.0.identity_number_encrypted')
        ->missing('applications.data.0.identity_number_hash')
        ->missing('applications.data.0.ocr_result_encrypted')
        ->missing('applications.data.0.documents'));
    $this->get($base.'/kyc?company='.$this->company->id.'&status=PENDING')->assertOk()->assertInertia(fn ($page) => $page->has('applications.data', 0));
    $this->get($base.'/wallets?company='.$this->company->id.'&search='.$user->account_id)->assertOk()->assertInertia(fn ($page) => $page
        ->component('platform/Wallets')->has('wallets.data', 1)
        ->where('wallets.data.0.id', $wallet->id)
        ->where('wallets.data.0.accountId', $user->account_id)
        ->where('wallets.data.0.available', '0.00000000')
        ->where('wallets.data.0.securityDeposit', '0.00000000')
        ->where('wallets.data.0.held', '0.00000000')
        ->missing('wallets.data.0.accounts'));
    $this->get($base.'/wallets?company='.$this->company->id.'&search=user@b.localhost')->assertOk()->assertInertia(fn ($page) => $page->has('wallets.data', 0));
    expect(LedgerEntry::query()->count())->toBe($entriesBefore);
    Http::assertNothingSent();
});

it('rejects company memberships and missing platform read permissions', function (): void {
    foreach (['users' => 'users.read', 'kyc' => 'kyc.read', 'wallets' => 'wallet.read', 'topups' => 'wallet_topups.read', 'cards' => 'cards.read'] as $section => $permission) {
        $urls = ["http://admin.localhost/platform/{$section}"];
        foreach ($urls as $url) {
            $this->actingAs($this->companyOwner, 'platform_admin')->get($url)->assertForbidden();
        }
        DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('name', $permission)->value('id'))->delete();
        foreach ($urls as $url) {
            $this->actingAs($this->owner->fresh(), 'platform_admin')->get($url)->assertForbidden();
        }
    }
});

it('rejects suspended platform memberships and validates persisted company routes', function (): void {
    $this->actingAs($this->owner, 'platform_admin');
    foreach (['kyc', 'wallets'] as $section) {
        $this->get('http://admin.localhost/platform/tenants/'.Str::uuid().'/'.$section)->assertNotFound();
        $this->getJson("http://admin.localhost/platform/{$section}?page=-1")->assertUnprocessable();
    }
    $this->owner->memberships()->update(['status' => 'SUSPENDED']);
    $this->get('http://admin.localhost/platform/kyc')->assertForbidden();
    $this->get('http://admin.localhost/platform/wallets')->assertForbidden();
    $this->get('http://admin.localhost/platform/users')->assertForbidden();
});

it('handles empty company searches and rejects invalid review filters', function (): void {
    $this->actingAs($this->owner, 'platform_admin');
    $this->get('http://admin.localhost/platform/wallets?search=not-a-company')->assertOk()
        ->assertInertia(fn ($page) => $page->has('wallets.data', 0)->where('filters.search', 'not-a-company'));
    $this->getJson('http://admin.localhost/platform/kyc?status=INVALID')->assertUnprocessable();
});

it('rejects invalid companies and retains legacy company links', function (): void {
    $this->actingAs($this->owner, 'platform_admin');
    foreach (['users', 'kyc', 'wallets', 'topups', 'cards'] as $section) {
        $this->getJson('http://admin.localhost/platform/'.$section.'?company='.Str::uuid())->assertUnprocessable();
    }
    foreach (['kyc', 'wallets'] as $section) {
        $this->get("http://admin.localhost/platform/tenants/{$this->company->id}/{$section}")->assertRedirect('/platform/'.$section.'?company='.$this->company->id);
    }
});

it('lists all users and filters company account and status without exposing private profile data', function (): void {
    $this->actingAs($this->owner, 'platform_admin');
    $user = User::query()->where('tenant_id', $this->company->id)->firstOrFail();
    $this->get('http://admin.localhost/platform/users')->assertOk()->assertInertia(fn ($page) => $page
        ->where('users.total', User::query()->count()));
    $this->get('http://admin.localhost/platform/users?company='.$this->company->id.'&search='.$user->account_id)->assertOk()
        ->assertInertia(fn ($page) => $page->has('users.data', 1)
            ->where('users.data.0.id', $user->id)->where('users.data.0.companyId', $this->company->id)
            ->where('users.data.0.companyName', $this->company->name)
            ->missing('users.data.0.password_hash')->missing('users.data.0.session_version')
            ->missing('users.data.0.date_of_birth')->missing('users.data.0.residential_address'));
    $this->get('http://admin.localhost/platform/users?company='.$this->company->id.'&search=user@b.localhost')->assertOk()
        ->assertInertia(fn ($page) => $page->has('users.data', 0));
    $this->get('http://admin.localhost/platform/users?status=SUSPENDED')->assertOk()
        ->assertInertia(fn ($page) => $page->has('users.data', 0));
    $this->getJson('http://admin.localhost/platform/users?status=INVALID')->assertUnprocessable();
    $this->getJson('http://admin.localhost/platform/users?page=-1')->assertUnprocessable();
    Http::assertNothingSent();
});
