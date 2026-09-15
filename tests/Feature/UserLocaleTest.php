<?php

use App\Application\User\UpdateUserLocaleAction;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantLocale;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserPreference;
use App\Http\Middleware\ResolveUserLocale;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed();
    $this->localeTenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->localeUser = User::query()->where('tenant_id', $this->localeTenant->id)->where('email', 'user@a.localhost')->firstOrFail();
    foreach (['zh-CN', 'ms', 'es'] as $locale) {
        TenantLocale::query()->updateOrCreate(['tenant_id' => $this->localeTenant->id, 'locale' => $locale], ['enabled' => true, 'is_default' => false]);
    }
});

it('resolves browser languages including region subtags from enabled tenant locales', function (string $header, string $expected): void {
    $this->withHeader('Accept-Language', $header)->get('http://a.localhost/login')
        ->assertInertia(fn (Assert $page) => $page->where('i18n.locale', $expected)->has('i18n.enabledLocales', 4));
})->with([['zh-CN, en;q=0.8', 'zh-CN'], ['es-MX, en;q=0.5', 'es'], ['ms-MY', 'ms'], ['fr-FR', 'en']]);

it('uses tenant default not first enabled locale when browser language is unsupported', function (): void {
    $this->localeTenant->locales()->update(['is_default' => false]);
    $this->localeTenant->locales()->where('locale', 'zh-CN')->update(['is_default' => true]);
    $this->withHeader('Accept-Language', 'fr-FR')->get('http://a.localhost/login')
        ->assertInertia(fn (Assert $page) => $page->where('i18n.locale', 'zh-CN'));
});

it('prefers the scoped cookie over browser language and user preference over both', function (): void {
    $cookie = ResolveUserLocale::cookieName($this->localeTenant->id);
    $this->withCookie($cookie, 'zh-CN')->withHeader('Accept-Language', 'es')->get('http://a.localhost/login')
        ->assertInertia(fn (Assert $page) => $page->where('i18n.locale', 'zh-CN'));
    UserPreference::query()->updateOrCreate(['tenant_id' => $this->localeTenant->id, 'user_id' => $this->localeUser->id], ['locale' => 'ms']);
    $this->actingAs($this->localeUser, 'tenant_user')->get('http://a.localhost/account')
        ->assertInertia(fn (Assert $page) => $page->where('i18n.locale', 'ms'));
});

it('ignores unsupported disabled and other tenant cookie preferences', function (): void {
    $this->localeTenant->locales()->where('locale', 'ms')->update(['enabled' => false]);
    UserPreference::query()->updateOrCreate(['tenant_id' => $this->localeTenant->id, 'user_id' => $this->localeUser->id], ['locale' => 'ms']);
    $other = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $this->actingAs($this->localeUser, 'tenant_user')
        ->withCookie(ResolveUserLocale::cookieName($other->id), 'es')
        ->withCookie(ResolveUserLocale::cookieName($this->localeTenant->id), 'invalid')
        ->withHeader('Accept-Language', 'zh-CN')->get('http://a.localhost/account')
        ->assertInertia(fn (Assert $page) => $page->where('i18n.locale', 'zh-CN')->missing('i18n.enabledLocales.3'));
});

it('persists locale with a host only secure httponly cookie and no identity for guests', function (): void {
    $count = UserPreference::query()->count();
    $response = $this->postJson('https://a.localhost/locale', ['locale' => 'zh-CN'])->assertOk()->assertJson(['locale' => 'zh-CN']);
    $name = ResolveUserLocale::cookieName($this->localeTenant->id);
    $response->assertCookie($name, 'zh-CN');
    $cookie = collect($response->headers->getCookies())->first(fn ($item) => $item->getName() === $name);
    expect($cookie->getDomain())->toBe('')->and((string) $cookie)->not->toContain('domain=')->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->isHttpOnly())->toBeTrue()->and($cookie->getSameSite())->toBe('lax')
        ->and(UserPreference::query()->count())->toBe($count);
});

it('persists authenticated preferences for reloads without accepting client tenant or user selectors', function (): void {
    $this->actingAs($this->localeUser, 'tenant_user')->postJson('http://a.localhost/locale', ['locale' => 'zh-CN'])->assertOk();
    $this->get('http://a.localhost/account')->assertInertia(fn (Assert $page) => $page->where('i18n.locale', 'zh-CN'));
    $this->postJson('http://a.localhost/locale', ['locale' => 'es', 'tenant_id' => 'another-tenant', 'user_id' => 'another-user'])
        ->assertUnprocessable()->assertJsonValidationErrors(['tenant_id', 'user_id']);
    expect($this->localeUser->preference()->value('locale'))->toBe('zh-CN');
});

it('rejects disabled and unsupported languages without changing preference', function (string $locale): void {
    $this->localeTenant->locales()->where('locale', 'ms')->update(['enabled' => false]);
    $before = $this->localeUser->preference()->value('locale');
    $this->actingAs($this->localeUser, 'tenant_user')->postJson('http://a.localhost/locale', ['locale' => $locale])
        ->assertUnprocessable()->assertJsonValidationErrors('locale');
    expect($this->localeUser->preference()->value('locale'))->toBe($before);
})->with(['ms', 'fr', '../../en']);

it('cannot modify another tenant user preference even if the action receives that user', function (): void {
    $other = User::query()->where('email', 'user@b.localhost')->firstOrFail();
    expect(fn () => app(UpdateUserLocaleAction::class)->execute($this->localeTenant, $other, 'zh-CN'))
        ->toThrow(ModelNotFoundException::class);
    expect($other->preference()->value('locale'))->toBe('en');
});

it('invalidates a foreign tenant session before locale mutation', function (): void {
    $other = User::query()->where('email', 'user@b.localhost')->firstOrFail();
    $this->actingAs($other, 'tenant_user')->postJson('http://a.localhost/locale', ['locale' => 'zh-CN'])->assertOk();
    $this->assertGuest('tenant_user');
    expect($other->preference()->value('locale'))->toBe('en');
});

it('allows suspended users to change language but preserves tenant lifecycle restrictions', function (): void {
    $this->localeUser->update(['status' => UserStatus::Suspended]);
    $this->localeTenant->update(['status' => TenantStatus::Suspended, 'suspended_at' => now()]);
    $this->actingAs($this->localeUser, 'tenant_user')->postJson('http://a.localhost/locale', ['locale' => 'zh-CN'])->assertOk();
    $this->localeTenant->update(['status' => TenantStatus::Closed, 'closed_at' => now()]);
    $this->postJson('http://a.localhost/locale', ['locale' => 'es'])->assertStatus(503);
});

it('does not leak consumer locale into admin or later requests', function (): void {
    $this->withHeader('Accept-Language', 'zh-CN')->get('http://a.localhost/login')->assertOk();
    expect(app()->getLocale())->toBe('en');
    $this->get('http://a.localhost/admin/login')->assertInertia(fn (Assert $page) => $page->where('i18n.locale', 'zh-CN'));
    $this->get('http://admin.localhost/platform/login')->assertInertia(fn (Assert $page) => $page->where('i18n.locale', 'zh-CN'));
});
