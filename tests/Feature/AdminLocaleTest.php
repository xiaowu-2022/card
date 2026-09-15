<?php

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\UserPreference;
use App\Http\Middleware\ResolveUserLocale;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed();
    $this->adminLocaleTenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
});

it('defaults both admin login surfaces to Chinese independently of consumer locales and browser preferences', function (string $url, string $surface): void {
    $this->withHeader('Accept-Language', 'en-US,es;q=0.9')
        ->withCookie(ResolveUserLocale::cookieName($this->adminLocaleTenant->id), 'en')
        ->get($url)->assertOk()->assertSee('lang="zh-CN"', false)->assertInertia(fn (Assert $page) => $page
        ->where('i18n.locale', 'zh-CN')->where('i18n.surface', $surface)
        ->where('i18n.enabledLocales', ['zh-CN', 'en']));
    expect(app()->getLocale())->toBe('en');
})->with([
    ['http://admin.localhost/platform/login', 'platform'],
    ['http://a.localhost/admin/login', 'tenant-admin'],
]);

it('saves a guest admin preference in an encrypted host-only surface-path cookie without altering accounts', function (string $host, string $path): void {
    $name = $path === '/platform' ? 'platform_admin_locale' : 'admin_locale_'.$this->adminLocaleTenant->id;
    $before = UserPreference::query()->get()->toArray();
    $tenantBefore = $this->adminLocaleTenant->toArray();
    $response = $this->postJson('https://'.$host.$path.'/locale', ['locale' => 'en'])
        ->assertOk()->assertExactJson(['locale' => 'en'])->assertCookie($name, 'en');
    $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === $name);
    expect($cookie->getDomain())->toBe('')->and($cookie->getPath())->toBe($path)
        ->and($cookie->isHttpOnly())->toBeTrue()->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')->and($cookie->getValue())->not->toBe('en')
        ->and(UserPreference::query()->get()->toArray())->toBe($before)
        ->and($this->adminLocaleTenant->fresh()->toArray())->toBe($tenantBefore);
    $this->withUnencryptedCookie($name, $cookie->getValue())->get('https://'.$host.$path.'/login')
        ->assertInertia(fn (Assert $page) => $page->where('i18n.locale', 'en'));
    $this->assertGuest('tenant_admin')->assertGuest('platform_admin')->assertGuest('tenant_user');
})->with([['a.localhost', '/admin'], ['admin.localhost', '/platform']]);

it('rejects non-admin languages and client identity selectors', function (string $path): void {
    foreach (['ms', 'es', 'zh-TW', 'fr', '../../en', ''] as $locale) {
        $this->postJson($path, ['locale' => $locale])->assertUnprocessable()->assertJsonValidationErrors('locale');
    }
    $this->postJson($path, ['locale' => 'en', 'tenant_id' => 'foreign', 'user_id' => 'foreign', 'admin_id' => 'foreign'])
        ->assertUnprocessable()->assertJsonValidationErrors(['tenant_id', 'user_id', 'admin_id']);
})->with(['http://a.localhost/admin/locale', 'http://admin.localhost/platform/locale']);

it('does not let another tenant cookie or an unsupported preference change the default', function (): void {
    $other = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $this->withCookie('admin_locale_'.$other->id, 'en')->withCookie('platform_admin_locale', 'en')
        ->withCookie('admin_locale_'.$this->adminLocaleTenant->id, 'es')
        ->get('http://a.localhost/admin/login')->assertInertia(fn (Assert $page) => $page->where('i18n.locale', 'zh-CN'));
});

it('keeps admin language separate from the consumer language', function (): void {
    $this->withCookie('admin_locale_'.$this->adminLocaleTenant->id, 'zh-CN')->withHeader('Accept-Language', 'en')
        ->get('http://a.localhost/login')->assertInertia(fn (Assert $page) => $page->where('i18n.locale', 'en')->where('i18n.surface', 'user'));
});

it('retains language on authenticated admin pages without changing the membership scope', function (): void {
    $owner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $this->actingAs($owner, 'tenant_admin')->withCookie('admin_locale_'.$this->adminLocaleTenant->id, 'en')
        ->get('http://a.localhost/admin/users')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('i18n.locale', 'en')->where('tenant.id', $this->adminLocaleTenant->id));
    $this->get('http://b.localhost/admin/users')->assertForbidden();
});

it('retains platform locale without treating platform host as a tenant domain', function (): void {
    $owner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->actingAs($owner, 'platform_admin')->withCookie('platform_admin_locale', 'en')
        ->get('http://admin.localhost/platform/tenants')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('i18n.locale', 'en')->where('i18n.surface', 'platform')->where('tenant', null));
    $this->postJson('http://a.localhost/platform/locale', ['locale' => 'en'])->assertNotFound();
});

it('does not bypass tenant lifecycle or authentication through language selection', function (): void {
    $this->postJson('http://a.localhost/admin/locale', ['locale' => 'en'])->assertOk();
    $this->get('http://a.localhost/admin/users')->assertRedirect('/admin/login');
    $this->adminLocaleTenant->update(['status' => TenantStatus::Closed, 'closed_at' => now()]);
    $this->postJson('http://a.localhost/admin/locale', ['locale' => 'en'])->assertStatus(503);
});

it('protects admin locale endpoints with normal web CSRF validation', function (): void {
    // Laravel skips CSRF in testing; enable its ordinary check for these requests only.
    $this->app->instance('env', 'local');
    $this->postJson('http://a.localhost/admin/locale', ['locale' => 'en'])->assertStatus(419);
    $this->postJson('http://admin.localhost/platform/locale', ['locale' => 'en'])->assertStatus(419);
});
