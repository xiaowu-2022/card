<?php

namespace App\Application\Cleanup;

use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DataCleanup
{
    public function __construct(private PlanDataCleanup $planner, private CleanupBackup $backups, private CleanupTombstones $tombstones) {}

    public function preview(string $tenant, AdminUser $actor, array $selection): array
    {
        $this->authorize($actor);
        Tenant::whereKey($tenant)->firstOrFail();
        sort($selection['user_ids']);
        sort($selection['modules']);
        $plan = DB::transaction(function () use ($tenant, $selection) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            return $this->planner->build($tenant, $selection);
        });
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $tenant, $actor, $selection, $plan): void {
            DB::table('data_cleanup_batches')->insert([
                'id' => $id, 'tenant_id' => $tenant, 'actor_id' => $actor->id, 'selection' => json_encode($selection, JSON_THROW_ON_ERROR),
                'summary' => json_encode($plan['summary'], JSON_THROW_ON_ERROR), 'fingerprint' => $plan['fingerprint'],
                'status' => 'PREVIEW', 'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (array_chunk($plan['items'], 500) as $items) {
                DB::table('data_cleanup_items')->insert(array_map(fn ($item) => ['batch_id' => $id, ...$item], $items));
            }
        });
        return $this->read($tenant, $id, $actor);
    }

    public function execute(string $tenant, string $batchId, AdminUser $actor, string $requestId, bool $confirmed): array
    {
        $this->authorize($actor);
        if (! $confirmed || ! Str::isUuid($requestId)) {
            throw new DomainException('CLEANUP_CONFIRMATION_REQUIRED', 'Confirm the complete cleanup preview before deleting.', 422);
        }
        // Lock tables in a stable order. This also covers writers which do not
        // take the Tenant lock; no provider call or financial replay occurs here.
        DB::transaction(function () use ($tenant, $batchId, $actor, $requestId): void {
            DB::statement("SET LOCAL lock_timeout='2s'");
            $tables = CleanupSchema::TABLES;
            sort($tables);
            DB::statement('LOCK TABLE '.implode(',', array_map(CleanupSchema::quoted(...), $tables)).' IN SHARE ROW EXCLUSIVE MODE');
            Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $batch = DB::table('data_cleanup_batches')->where('id', $batchId)->where('tenant_id', $tenant)->where('actor_id', $actor->id)->lockForUpdate()->first();
            abort_if($batch === null, 404);
            $this->authorize($actor);
            if ($batch->status === 'COMPLETED') {
                abort_unless($batch->request_id === $requestId, 409);
                return;
            }
            if ($batch->status !== 'PREVIEW' || now()->greaterThan($batch->expires_at)) {
                throw new DomainException('CLEANUP_PREVIEW_EXPIRED', 'Cleanup preview expired. Preview again.', 409);
            }
            $used = DB::table('data_cleanup_batches')->where('actor_id', $actor->id)->where('request_id', $requestId)->where('id', '<>', $batchId)->exists();
            if ($used) {
                throw new DomainException('CLEANUP_REQUEST_CONFLICT', 'This cleanup request has already been used.', 409);
            }
            $selection = json_decode($batch->selection, true, flags: JSON_THROW_ON_ERROR);
            $plan = $this->planner->build($tenant, $selection);
            if (! hash_equals($batch->fingerprint, $plan['fingerprint'])) {
                throw new DomainException('CLEANUP_PREVIEW_STALE', 'Data changed after preview. Preview again.', 409);
            }
            if ($plan['summary']['blockers'] !== [] || $plan['summary']['total'] === 0) {
                throw new DomainException('CLEANUP_BLOCKED', 'Resolve the cleanup blockers before deleting.', 409);
            }
            $this->assertNotInFlight($plan['snapshot']);
            $backup = $this->backups->write($batchId, ['version' => 1, 'tenant' => $tenant, 'batch' => $batchId, 'selection' => $selection,
                'records' => $plan['snapshot'], 'balances' => $plan['summary']['balances'], 'images' => $plan['images']]);
            $expires = now()->addDays(7);
            DB::table('data_cleanup_batches')->where('id', $batchId)->update([
                'status' => 'EXECUTING', 'request_id' => $requestId, 'execution_pid' => DB::raw('pg_backend_pid()'), 'execution_txid' => DB::raw('txid_current()'),
                'backup_path' => $backup['path'], 'backup_hash' => $backup['hash'], 'backup_expires_at' => $expires, 'updated_at' => now(),
            ]);
            DB::select("SELECT set_config('card.cleanup_batch',?,true)", [$batchId]);
            $this->tombstones->reserve($batchId, $tenant, $plan['snapshot']);
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            // FK cycles are deferred, not disabled. Every manifest row must still
            // match its preview hash and all remaining relations must pass at commit.
            foreach ($plan['items'] as $item) {
                $table = CleanupSchema::quoted($item['table_name']);
                if ($item['operation'] === 'RETIRE') {
                    $count = DB::update("UPDATE $table AS t SET cleanup_member_id=member_id,member_id=NULL WHERE to_jsonb(t) @> ?::jsonb AND md5(to_jsonb(t)::text)=?", [$item['row_key'], $item['row_hash']]);
                } else {
                    $count = DB::delete("DELETE FROM $table AS t WHERE to_jsonb(t) @> ?::jsonb AND md5(to_jsonb(t)::text)=?", [$item['row_key'], $item['row_hash']]);
                }
                if ($count !== 1) {
                    throw new DomainException('CLEANUP_PREVIEW_STALE', 'Data changed after preview. Preview again.', 409);
                }
            }
            foreach ($plan['summary']['balances'] as $balance) {
                if (! $balance['removed']) {
                    DB::update('UPDATE ledger_accounts a SET balance=(SELECT COALESCE(SUM(p.delta),0) FROM ledger_postings p JOIN ledger_entries e ON e.id=p.ledger_entry_id WHERE p.ledger_account_id=a.id AND e.sealed_at IS NOT NULL),updated_at=now() WHERE a.id=? AND a.tenant_id=?', [$balance['id'], $tenant]);
                }
            }
            foreach ($plan['images'] as $image) {
                DB::table('data_cleanup_images')->insert(['batch_id' => $batchId, 'tenant_id' => $tenant, 'location_encrypted' => Crypt::encryptString(json_encode($image, JSON_THROW_ON_ERROR)), 'delete_after' => $expires]);
                DB::table('stored_images')->where('tenant_id', $tenant)->where('source_disk', $image['disk'])->where('source_key', $image['key'])->update(['cleanup_after' => $expires, 'updated_at' => now()]);
            }
            app(AuditLogger::class)->record($tenant, 'ADMIN', $actor->id, 'PLATFORM_DATA_CLEANED', 'data_cleanup_batch', $batchId,
                [], ['user_ids' => $selection['user_ids'], 'modules' => $selection['modules'], 'counts' => $plan['summary']['counts'], 'balances' => $plan['summary']['balances']], $requestId);
            DB::table('data_cleanup_batches')->where('id', $batchId)->update(['status' => 'COMPLETED', 'completed_at' => now(), 'execution_pid' => null, 'execution_txid' => null, 'updated_at' => now()]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
        return $this->read($tenant, $batchId, $actor);
    }

    public function read(string $tenant, string $batch, AdminUser $actor): array
    {
        $this->authorize($actor);
        $row = DB::table('data_cleanup_batches')->where('tenant_id', $tenant)->where('id', $batch)->where('actor_id', $actor->id)->first();
        abort_if($row === null, 404);
        return ['id' => $row->id, 'status' => $row->status, 'selection' => json_decode($row->selection, true), 'summary' => json_decode($row->summary, true),
            'expiresAt' => $row->expires_at, 'completedAt' => $row->completed_at];
    }

    private function authorize(AdminUser $actor): void
    {
        $actor->refresh();
        $auth = app(AuthorizationService::class);
        abort_unless($auth->allows($actor, ScopeType::Platform, null, 'users.read') && $auth->allows($actor, ScopeType::Platform, null, 'users.cleanup'), 403);
    }

    private function assertNotInFlight(array $snapshot): void
    {
        foreach ($snapshot as $table => $records) {
            foreach ($records as $row) {
                $lease = $row['initiation_lease_expires_at'] ?? null;
                if ($lease && now()->lessThan($lease)
                    || $table === 'card_issue_orders' && $row['status'] === 'PROCESSING'
                    || $table === 'provider_cardholders' && $row['status'] === 'SUBMITTING'
                    || $table === 'card_management_orders' && $row['status'] === 'PROCESSING'
                    || $table === 'card_transaction_sync_items' && $row['status'] === 'RUNNING') {
                    throw new DomainException('CLEANUP_BUSY', 'A related operation is in progress. Try again after it finishes.', 409);
                }
            }
        }
    }
}
