<?php

use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('exports isolated read-only consumer fixtures for old and generated H5 comparison', function () {
    $this->seed();
    Http::preventStrayRequests();
    $tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $user = User::where('tenant_id', $tenant->id)->where('email', 'user@a.localhost')->firstOrFail();
    $version = app(\App\Http\Middleware\HandleInertiaRequests::class)->version(\Illuminate\Http\Request::create('http://a.localhost'));
    $before = DB::table('ledger_entries')->count();
    $fixtures = ['pages' => [], 'api' => []];
    foreach (['/', '/register', '/forgot-password'] as $path) {
        $fixtures['pages'][$path] = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->get('http://a.localhost'.$path)->assertOk()->json();
    }
    $this->flushHeaders();
    $fixtures['guest'] = $this->getJson('http://a.localhost/api/v1/bootstrap')->assertOk()->json();
    $this->actingAs($user, 'tenant_user')->withSession(['tenant_user_session_version' => $user->session_version]);
    $this->flushHeaders();
    $fixtures['authenticated'] = $this->getJson('http://a.localhost/api/v1/bootstrap')->assertOk()->json();
    foreach (['/dashboard', '/account', '/account/settings', '/account/security', '/kyc', '/cards', '/wallet', '/wallet/top-up', '/wallet/transfer', '/security-deposit', '/security-deposit/history', '/wealth', '/wealth/assets/USDT', '/promotion', '/promotion/membership', '/promotion/invitations', '/promotion/rules', '/promotion/registration', '/promotion/features', '/promotion/reward-guide', '/promotion/direct', '/promotion/daily', '/promotion/commissions', '/messages', '/support', '/about'] as $path) {
        $this->flushHeaders();
        $response = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->get('http://a.localhost'.$path);
        if ($response->isRedirection()) {
            $fixtures['pages'][$path] = ['redirect' => parse_url($response->headers->get('Location'), PHP_URL_PATH)];
        } else {
            $response->assertOk();
            $fixtures['pages'][$path] = $response->json();
        }
    }
    $this->flushHeaders();
    foreach (['/account', '/messages', '/support', '/unread'] as $path) {
        $fixtures['api'][$path] = $this->getJson('http://a.localhost/api/v1'.$path)->assertOk()->json();
    }
    expect(DB::table('ledger_entries')->count())->toBe($before);
    if (getenv('UNI_PARITY_EXPORT') === '1') {
        $dir = storage_path('framework/testing/uni-parity');
        if (! is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($dir.'/fixtures.json', json_encode($fixtures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
});
