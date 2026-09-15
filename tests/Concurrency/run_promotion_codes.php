<?php

declare(strict_types=1);

use App\Application\Promotion\PromotionMembershipAction;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'card_ui_test' || ! extension_loaded('pcntl')) {
    fwrite(STDERR, "Requires testing, card_ui_test and pcntl.\n");
    exit(2);
}

// Different companies deliberately avoid serialization through a shared Tenant lock.
$fixtures = [];
foreach (range(1, 6) as $n) {
    $tenant = Tenant::query()->create(['name' => 'Invitation concurrency fixture', 'slug' => 'invitation-race-'.Str::uuid(), 'status' => 'ACTIVE', 'timezone' => 'UTC']);
    $user = User::query()->create(['tenant_id' => $tenant->id, 'email' => 'fixture@example.test', 'password_hash' => 'Non-login fixture', 'status' => 'ACTIVE']);
    $fixtures[] = [$tenant->id, $user->id];
}
$start = DB::table('promotion_invitation_counter')->value('next_value');
DB::disconnect();
$children = [];
foreach ($fixtures as $index => [$tenantId, $userId]) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Unable to fork test worker.');
    }
    if ($pid === 0) {
        DB::reconnect();
        try {
            DB::transaction(function () use ($tenantId, $userId, $index): void {
                $action = app(PromotionMembershipAction::class);
                if ($index % 2 === 0) {
                    $action->companyInvitation($tenantId);
                } else {
                    $action->ensure($tenantId, $userId);
                }
                DB::select('SELECT pg_sleep(0.1)');
                if ($index === 5) {
                    throw new LogicException('Intentional test rollback');
                }
            });
            exit(0);
        } catch (LogicException) {
            exit(10);
        } catch (Throwable) {
            exit(20);
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
$tenantIds = array_column($fixtures, 0);
$codes = DB::table('promotion_members')->whereIn('tenant_id', $tenantIds)->pluck('invitation_code')
    ->merge(DB::table('promotion_company_invitations')->whereIn('tenant_id', $tenantIds)->pluck('invitation_code'))->sort()->values()->all();
if ($statuses !== [0, 0, 0, 0, 0, 10] || $codes !== array_map(strval(...), range($start, $start + 4))
    || DB::table('promotion_invitation_counter')->value('next_value') !== $start + 5) {
    throw new RuntimeException('Concurrent allocation/rollback invariant failed.');
}
echo "PASS: six concurrent connections across companies; five consecutive unique codes, one rollback, no gap.\n";
// Immutable fixtures stay only in the isolated DB until the next test refresh.
