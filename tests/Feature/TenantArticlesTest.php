<?php

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Models\Role;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantArticle;
use App\Domain\Tenant\Models\TenantLocale;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed();
    $this->articleTenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->articleOther = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $this->articleAdmin = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->articleUser = User::query()->where('tenant_id', $this->articleTenant->id)->where('email', 'user@a.localhost')->firstOrFail();
    $this->articleUser->preference()->delete(); // Let these fixtures exercise request-language resolution.
    TenantLocale::query()->updateOrCreate(['tenant_id' => $this->articleTenant->id, 'locale' => 'zh-CN'], ['enabled' => true, 'is_default' => false]);
});

it('saves each fixed article and language for the resolved company only', function (string $key): void {
    $other = TenantArticle::query()->create(['tenant_id' => $this->articleOther->id, 'article_key' => $key, 'locale' => 'zh-CN', 'body' => 'Other company']);
    $this->actingAs($this->articleAdmin, 'platform_admin');
    foreach (['zh-CN' => '本公司文章', 'en' => 'Our company article', 'ms' => 'Artikel', 'es' => 'Artículo'] as $locale => $body) {
        $this->post("http://admin.localhost/platform/tenants/{$this->articleTenant->id}/configuration/settings/articles/{$key}/{$locale}", ['body' => $body])
            ->assertRedirect("/platform/tenants/{$this->articleTenant->id}/configuration/settings/articles")->assertSessionHas('success', 'Article saved.');
        $this->assertDatabaseHas('tenant_articles', ['tenant_id' => $this->articleTenant->id, 'article_key' => $key, 'locale' => $locale, 'body' => $body]);
    }
    expect($other->fresh()->body)->toBe('Other company');
    $this->get("http://admin.localhost/platform/tenants/{$this->articleTenant->id}/configuration/settings/articles")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('tenant-admin/Settings')->where('section', 'articles')->has('settings.articles', 4)
        ->where('settings.articles', fn ($items) => collect($items)->every(fn ($item) => array_keys($item) === ['key', 'locale', 'body'] && $item['body'] !== 'Other company')));
    $this->get("http://admin.localhost/platform/tenants/{$this->articleTenant->id}/configuration/settings/branding")->assertInertia(fn (Assert $page) => $page->missing('settings.articles'));
})->with(['terms', 'privacy', 'account-closure']);

it('updates one existing version and audits metadata without copying the body', function (): void {
    $this->actingAs($this->articleAdmin, 'platform_admin');
    foreach (['First policy text', 'Second policy text'] as $body) {
        $this->post("http://admin.localhost/platform/tenants/{$this->articleTenant->id}/configuration/settings/articles/terms/zh-CN", ['body' => $body])->assertRedirect();
    }
    $article = TenantArticle::query()->where('tenant_id', $this->articleTenant->id)->sole();
    expect($article->body)->toBe('Second policy text');
    $logs = AuditLog::query()->where('tenant_id', $this->articleTenant->id)->where('action', 'TENANT_ARTICLE_UPDATED')->get();
    expect($logs)->toHaveCount(2);
    foreach ($logs as $log) {
        expect($log->resource_id)->toBe($article->id)->and($log->before_data)->toBeNull()
            ->and($log->after_data)->toEqual(['article_key' => 'terms', 'locale' => 'zh-CN', 'configured' => true])
            ->and($log->toJson())->not->toContain('policy text');
    }
});

it('uses only the resolved enabled consumer language with no company or language fallback', function (): void {
    foreach ([[$this->articleTenant, 'en', 'English A'], [$this->articleOther, 'zh-CN', 'Chinese B'], [$this->articleTenant, 'ms', 'Disabled A']] as [$tenant, $locale, $body]) {
        TenantArticle::query()->create(['tenant_id' => $tenant->id, 'article_key' => 'terms', 'locale' => $locale, 'body' => $body]);
    }
    $this->actingAs($this->articleUser, 'tenant_user')->withHeader('Accept-Language', 'zh-CN');
    $this->get('http://a.localhost/about')->assertOk()->assertInertia(fn (Assert $page) => $page->component('user/About'));
    $this->get('http://a.localhost/about/terms?tenant_id='.$this->articleOther->id.'&locale=en')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('user/AboutArticle')->where('article', ['key' => 'terms', 'locale' => 'zh-CN', 'body' => null]));
    $this->withHeader('Accept-Language', 'en')->get('http://a.localhost/about/terms')
        ->assertInertia(fn (Assert $page) => $page->where('article.body', 'English A'));
    $this->articleTenant->locales()->where('locale', 'ms')->update(['enabled' => false]);
    $this->withHeader('Accept-Language', 'ms,en;q=0.5')->get('http://a.localhost/about/terms')
        ->assertInertia(fn (Assert $page) => $page->where('article.locale', 'en')->where('article.body', 'English A'));
    $this->get('http://b.localhost/about/terms')->assertRedirect('/login');
});

it('preserves plain text and line breaks while treating empty content as unavailable', function (): void {
    $body = "Policy title\n\n<script>window.unsafe=true</script> & <b>plain text</b>";
    $this->actingAs($this->articleAdmin, 'platform_admin')->post("http://admin.localhost/platform/tenants/{$this->articleTenant->id}/configuration/settings/articles/privacy/zh-CN", ['body' => $body])->assertRedirect();
    $this->actingAs($this->articleUser, 'tenant_user')->withHeader('Accept-Language', 'zh-CN')->get('http://a.localhost/about/privacy')
        ->assertOk()->assertDontSee('<script>window.unsafe=true</script>', false)
        ->assertInertia(fn (Assert $page) => $page->where('article.body', $body)->missing('article.tenant_id')->missing('article.id'));
    $this->actingAs($this->articleAdmin, 'platform_admin')->post("http://admin.localhost/platform/tenants/{$this->articleTenant->id}/configuration/settings/articles/privacy/zh-CN", ['body' => '  '])->assertRedirect();
    $this->get('http://a.localhost/about/privacy')->assertInertia(fn (Assert $page) => $page->where('article.body', null));
});

it('denies unauthenticated and company-scope configuration mutations', function (): void {
    $url = "http://admin.localhost/platform/tenants/{$this->articleTenant->id}/configuration/settings/articles/terms/zh-CN";
    $this->post($url, ['body' => 'Denied'])->assertRedirect('/platform/login');
    $company = AdminUser::query()->where('email', 'owner@a.localhost')->sole();
    $this->actingAs($company, 'tenant_admin')->get('http://a.localhost/admin/settings/articles')->assertOk();
    $this->post('http://a.localhost/admin/settings/articles/terms/zh-CN', ['body' => 'Denied'])->assertForbidden();
    $this->get('http://b.localhost/admin/settings/articles')->assertForbidden();
    $this->actingAs($company, 'platform_admin')->post($url, ['body' => 'Denied'])->assertForbidden();
    expect(TenantArticle::query()->count())->toBe(0);
});

it('requires current Platform permission and active membership', function (): void {
    $url = "http://admin.localhost/platform/tenants/{$this->articleTenant->id}/configuration/settings/articles/terms/zh-CN";
    $membership = $this->articleAdmin->memberships()->where('scope_type', 'PLATFORM')->whereNull('scope_id')->sole();
    $membership->update(['role_id' => Role::query()->where('name', 'PLATFORM_AUDITOR')->value('id')]);
    $this->actingAs($this->articleAdmin, 'platform_admin')->post($url, ['body' => 'Denied'])->assertForbidden();
    $membership->update(['role_id' => Role::query()->where('name', 'PLATFORM_OWNER')->value('id'), 'status' => 'SUSPENDED']);
    $this->post($url, ['body' => 'Denied'])->assertForbidden();
    expect(TenantArticle::query()->count())->toBe(0);
});

it('rejects arbitrary articles locales identity selectors and invalid content', function (): void {
    $this->actingAs($this->articleAdmin, 'platform_admin');
    foreach (['custom/zh-CN', 'terms/fr'] as $suffix) {
        $this->post("http://admin.localhost/platform/tenants/{$this->articleTenant->id}/configuration/settings/articles/".$suffix, ['body' => 'Invalid'])->assertNotFound();
    }
    $this->postJson("http://admin.localhost/platform/tenants/{$this->articleTenant->id}/configuration/settings/articles/terms/zh-CN", ['body' => 'Invalid', 'tenant_id' => $this->articleOther->id, 'id' => 'foreign', 'article_key' => 'privacy', 'locale' => 'en'])
        ->assertUnprocessable()->assertJsonValidationErrors(['tenant_id', 'id', 'article_key', 'locale']);
    foreach ([[], ['body' => ['invalid']], ['body' => str_repeat('文', 50001)]] as $payload) {
        $this->postJson("http://admin.localhost/platform/tenants/{$this->articleTenant->id}/configuration/settings/articles/terms/zh-CN", $payload)->assertUnprocessable()->assertJsonValidationErrors('body');
    }
    expect(TenantArticle::query()->count())->toBe(0);
});

it('keeps consumer article routes within existing user and tenant availability policies', function (): void {
    $this->get('http://a.localhost/about')->assertRedirect('/login');
    $this->actingAs($this->articleUser, 'tenant_user')->get('http://a.localhost/about/custom')->assertNotFound();
    $this->articleUser->update(['status' => UserStatus::Suspended]);
    $this->get('http://a.localhost/about/terms')->assertRedirect('/account/restricted');
    $this->articleTenant->update(['status' => TenantStatus::Closed, 'closed_at' => now()]);
    $this->get('http://a.localhost/about')->assertStatus(503);
});

it('treats the closure article as content only without financial or account mutations', function (): void {
    $tables = ['users', 'wallets', 'ledger_accounts', 'ledger_entries', 'ledger_postings', 'user_cards', 'card_issue_orders'];
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $this->actingAs($this->articleAdmin, 'platform_admin')->post("http://admin.localhost/platform/tenants/{$this->articleTenant->id}/configuration/settings/articles/account-closure/zh-CN", ['body' => 'Contact our company for assistance.'])->assertRedirect();
    $this->actingAs($this->articleUser, 'tenant_user')->get('http://a.localhost/about/account-closure')->assertOk();
    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($before[$table]);
    }
});
