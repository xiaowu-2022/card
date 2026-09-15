<?php

// Read-only inventory. No provider calls, migrations, queue dispatch, or model writes.
use App\Domain\Tenant\Contracts\DomainVerificationService;
use App\Infrastructure\Providers\Domain\LocalDomainVerificationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$strict = in_array('--production', $argv, true);
$report = ['mode' => $strict ? 'production' : 'inventory', 'checks' => [], 'counts' => []];
$check = function (string $name, bool $ok) use (&$report): void {
    $report['checks'][$name] = $ok;
};
try {
    $manifest = json_decode(file_get_contents(__DIR__.'/../../docs/deployment/migrations.sha256.json'), true, 512, JSON_THROW_ON_ERROR);
    $paths = glob(base_path('database/migrations/*.php'));
    $expected = array_keys($manifest);
    $actual = array_map(fn ($path) => 'database/migrations/'.basename($path), $paths);
    sort($expected);
    sort($actual);
    $check('migration_file_set_matches_manifest', $expected === $actual);
    $check('migration_hashes_match', collect($manifest)->every(fn ($hash, $path) => is_file(base_path($path)) && hash_equals($hash, hash_file('sha256', base_path($path)))));
    DB::beginTransaction();
    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    $database = DB::selectOne('SELECT current_database() AS name')->name;
    $applied = DB::table('migrations')->pluck('migration')->all();
    $available = array_map(fn ($path) => basename($path, '.php'), $paths);
    $report['migrations'] = ['files' => count($available), 'applied' => count($applied), 'pending' => array_values(array_diff($available, $applied))];
    $check('applied_migrations_have_source', array_diff($applied, $available) === []);
    foreach (['tenants', 'users', 'user_cards', 'ledger_entries', 'ledger_postings'] as $table) {
        $report['counts'][$table] = DB::table($table)->count();
    }
    // Requires the current schema; an older schema must be rehearsed before this readiness check.
    $markers = [
        'local_merchants' => DB::table('platform_card_provider_references')->where('runtime_driver', 'LOCAL_MOCK')->count(),
        'sandbox_connections' => DB::table('platform_card_provider_references')->whereNotNull('photonpay_issuing_encrypted')->count(),
        'sandbox_holders' => DB::table('provider_cardholders')->where('sandbox_existing_holder', true)->count(),
        'mock_cards' => DB::table('user_cards')->where('provider_card_id', 'like', 'MOCK-%')->count(),
        'fixture_users' => DB::table('users')->where('email', 'like', '%@fixture.invalid')->count(),
    ];
    $report['non_production_markers'] = $markers;
    $check('persistent_keys_present', collect([
        config('app.key'), config('kyc.data_encryption_key'), config('kyc.identity_hash_key'),
        config('withdrawal.address_encryption_key'), config('withdrawal.address_hash_key'),
    ])->every(fn ($key) => is_string($key) && trim($key) !== ''));
    if ($strict) {
        $check('all_migrations_applied', $report['migrations']['pending'] === []);
        $check('production_environment', $app->environment('production'));
        $check('debug_disabled', config('app.debug') === false);
        $check('production_database', $database !== 'card_ui_test');
        $check('preserved_merchants_have_directory_routing', array_sum($markers) === 0 || config('card-provider.driver') === 'directory');
        $hostOk = fn ($host) => is_string($host) && $host !== '' && ! preg_match('/(^|\.)(localhost|local|test|invalid)$/i', $host);
        $check('https_public_url', parse_url(config('app.url'), PHP_URL_SCHEME) === 'https' && $hostOk(parse_url(config('app.url'), PHP_URL_HOST)));
        $check('platform_hostname_configured', $hostOk(config('tenancy.platform_admin_host')));
        $check('secure_host_only_sessions', config('session.secure') === true && ! config('session.domain'));
        $check('shared_cache_and_queue', config('cache.default') === 'redis' && config('queue.default') === 'redis');
        $check('configured_card_routing', in_array(config('card-provider.driver'), ['photonpay', 'directory'], true));
        $check('no_mock_payment_or_ocr_driver', config('payment.driver') !== 'mock' && config('kyc.ocr_driver') !== 'mock' && config('withdrawal.blockchain_driver') !== 'mock');
        $check('production_domain_verifier', ! ($app->make(DomainVerificationService::class) instanceof LocalDomainVerificationService));
        $weak = 0;
        foreach (['admin_users', 'users'] as $table) {
            foreach (DB::table($table)->whereIn('email', ['owner@platform.local', 'owner@a.localhost', 'owner@b.localhost', 'user@a.localhost', 'user@b.localhost'])->pluck($table === 'users' ? 'password_hash' : 'password') as $hash) {
                if (Hash::check('123456', $hash) || Hash::check('local-password', $hash)) {
                    $weak++;
                }
            }
        }
        $report['warnings']['retained_default_password_accounts'] = $weak;
    }
    DB::rollBack();
} catch (Throwable $error) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    // Exception text can contain connection credentials or SQL bindings; never print it.
    $check('inspection_completed', false);
    $report['error'] = 'Inspection failed. Check schema, connection and configuration privately; no sensitive exception was printed.';
}
$report['passed'] = ! in_array(false, $report['checks'], true);
$inventoryTables = ['tenants', 'users', 'user_cards', 'ledger_entries', 'ledger_postings'];
$countsComplete = count(array_intersect($inventoryTables, array_keys($report['counts']))) === count($inventoryTables);
$empty = $countsComplete && array_sum($report['counts']) === 0;
$report['business_data'] = ! $countsComplete ? 'unknown' : ($empty ? 'empty' : 'present');
$report['deployment_status'] = 'not_evaluated';
$report['message'] = $empty
    ? '当前数据库仅有结构，没有业务数据；未证明原数据已恢复，也不代表部署完成。'
    : '本检查只核对部分配置与数据；不代表部署完成或已通过正式业务验收。';
$report['scope'] = 'Static readiness only; does not certify financial provenance, provider acceptance, DNS/TLS, backup restoration, or complete deployment readiness.';
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($report['passed'] ? 0 : 1);
