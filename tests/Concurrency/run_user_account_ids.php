<?php

declare(strict_types=1);

use App\Domain\Tenant\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'card_ui_test' || ! extension_loaded('pcntl')) {
    fwrite(STDERR, "Requires testing environment, card_ui_test database and pcntl.\n");
    exit(2);
}

// Isolated fixture has no domains, sessions, wallets, KYC or card relationships.
$tenant = Tenant::query()->create(['name' => 'Account ID concurrency fixture', 'slug' => 'account-id-race-'.Str::uuid(), 'status' => 'ACTIVE', 'timezone' => 'UTC']);
$tenantId = $tenant->id;
try {
    DB::transaction(function () use ($tenantId): void {
        DB::statement('ALTER TABLE users DISABLE TRIGGER users_assign_account_id');
        DB::insert(<<<'SQL'
            INSERT INTO users (id, tenant_id, email, password_hash, status, created_at, updated_at, account_id)
            SELECT gen_random_uuid(), ?::uuid, 'occupied-' || n || '@account-id-race.test', 'Non-login fixture', 'ACTIVE',
                '2099-09-03 12:00:00+00'::timestamptz, '2099-09-03 12:00:00+00'::timestamptz,
                '20990903' || lpad(n::text, 4, '0')
            FROM generate_series(0, 9999) n WHERE n <> 42
            SQL, [$tenantId]);
        DB::statement('ALTER TABLE users ENABLE TRIGGER users_assign_account_id');
    });
    DB::disconnect();
    $children = [];
    foreach (range(1, 2) as $worker) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Could not start concurrency worker.');
        }
        if ($pid === 0) {
            DB::reconnect();
            try {
                DB::transaction(function () use ($tenantId, $worker): void {
                    DB::table('users')->insert([
                        'id' => (string) Str::uuid(), 'tenant_id' => $tenantId,
                        'email' => 'winner-'.$worker.'@account-id-race.test',
                        'password_hash' => 'Non-login fixture', 'status' => 'ACTIVE',
                        'created_at' => '2099-09-03 12:00:00+00', 'updated_at' => '2099-09-03 12:00:00+00',
                    ]);
                    // Keep the allocation lock held while the other connection waits.
                    DB::select('SELECT pg_sleep(0.3)');
                });
                exit(0);
            } catch (QueryException $exception) {
                exit($exception->getCode() === 'P2001' ? 10 : 20);
            } catch (Throwable) {
                exit(30);
            }
        }
        $children[] = $pid;
    }
    $statuses = [];
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        $statuses[] = pcntl_wexitstatus($status);
    }
    DB::reconnect();
    sort($statuses);
    $winners = DB::table('users')->where('tenant_id', $tenantId)->where('account_id', '209909030042')->count();
    $count = DB::table('users')->where('tenant_id', $tenantId)->count();
    if ($statuses !== [0, 10] || $winners !== 1 || $count !== 10000) {
        throw new RuntimeException('Concurrent allocation did not produce one success and one capacity error.');
    }
    echo "PASS: one remaining suffix, two connections, one immutable ID; loser reports capacity, not duplicate contact.\n";
} finally {
    DB::reconnect();
    // Remove only this newly created, private test fixture; no business history exists.
    DB::table('users')->where('tenant_id', $tenantId)->delete();
    DB::table('tenants')->where('id', $tenantId)->delete();
}
