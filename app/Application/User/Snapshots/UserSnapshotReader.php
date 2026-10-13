<?php

namespace App\Application\User\Snapshots;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class UserSnapshotReader
{
    private ?CarbonImmutable $at = null;

    public function earliest(?string $company = null): ?string
    {
        if (! Schema::hasTable('platform_user_snapshot_batches')) {
            return null;
        }
        $at = DB::table('platform_user_snapshot_batches')->where('baseline', true)->value('published_at');

        if (! $at) {
            return null;
        }
        $earliest = CarbonImmutable::parse($at)->ceilSecond();
        // Reviewed permanent cleanup also removes temporal copies. Do not offer
        // incomplete older snapshots of retained accounts after order-only cleanup.
        $cleanedAt = DB::table('data_cleanup_batches')->where('status', 'COMPLETED')
            ->when($company, fn ($q) => $q->where('tenant_id', $company))->max('completed_at');
        if ($cleanedAt) {
            $earliest = $earliest->max(CarbonImmutable::parse($cleanedAt)->ceilSecond()->addSecond());
        }

        return $earliest->setTimezone('Asia/Shanghai')->format('Y-m-d\TH:i:s');
    }

    public function cutoff(Request $request): ?CarbonImmutable
    {
        $data = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d\TH:i:s']]);
        if (empty($data['as_of'])) {
            return null;
        }
        $at = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s', $data['as_of'], 'Asia/Shanghai')->utc();
        $tenant = $request->route('tenant');
        $company = $tenant !== null ? (is_object($tenant) ? $tenant->id : $tenant) : $request->query('company');
        $earliest = $this->earliest($company);
        if (! $earliest || $at->lessThan(CarbonImmutable::parse($earliest, 'Asia/Shanghai'))) {
            throw ValidationException::withMessages(['as_of' => 'Historical snapshots are only available after the snapshot baseline.']);
        }
        if ($at->greaterThan(CarbonImmutable::now())) {
            throw ValidationException::withMessages(['as_of' => 'The cutoff time cannot be in the future.']);
        }

        return $at;
    }

    /** All callers authorize against live administration scope BEFORE entering here. */
    public function read(?CarbonImmutable $at, callable $read, ?string $company = null): mixed
    {
        $connection = DB::connection();
        $outer = $connection->transactionLevel();

        return $connection->transaction(function () use ($at, $read, $company, $connection, $outer) {
            if ($outer === 0) {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            if ($at === null) {
                return $read();
            }
            // Recheck coverage inside the same MVCC snapshot as the report. A cleanup
            // committing after request validation must not yield a partial old report.
            $earliest = $this->earliest($company);
            if (! $earliest || $at->lessThan(CarbonImmutable::parse($earliest, 'Asia/Shanghai'))) {
                throw ValidationException::withMessages(['as_of' => 'Historical snapshots are only available after the snapshot baseline.']);
            }
            $sources = DB::table('platform_user_snapshot_sources')->pluck('columns', 'table_name')->all();
            foreach (UserSnapshotSources::COLUMNS as $table => $columns) {
                if (json_decode($sources[$table] ?? 'null', true) !== $columns) {
                    throw ValidationException::withMessages(['as_of' => 'Historical snapshot schema is incomplete. Apply the matching migration.']);
                }
            }
            if ($this->at !== null) {
                throw new \LogicException('Nested user snapshot scopes are not supported.');
            }
            $grammar = $connection->getQueryGrammar();
            $immutableClock = CarbonImmutable::getTestNow();
            $mutableClock = Carbon::getTestNow();
            $this->at = $at;
            $connection->setQueryGrammar(new UserSnapshotGrammar($connection, $this));
            CarbonImmutable::setTestNow($at);
            Carbon::setTestNow($at);
            try {
                return $read();
            } finally {
                $connection->setQueryGrammar($grammar);
                CarbonImmutable::setTestNow($immutableClock);
                Carbon::setTestNow($mutableClock);
                $this->at = null;
            }
        });
    }

    public function active(): bool
    {
        return $this->at !== null;
    }

    public function relation(string $table): string
    {
        if ($this->at === null || ! isset(UserSnapshotSources::COLUMNS[$table])) {
            throw new \LogicException('Unknown temporal source.');
        }
        // Only fixed source names and a parsed server timestamp are interpolated.
        $time = $this->at->format('Y-m-d H:i:s.uP');

        $observed = $table === 'asset_market_snapshots' ? " AND (h.row_data->>'observed_at')::timestamptz<='$time'::timestamptz" : '';

        return "SELECT (jsonb_populate_record(NULL::public.\"$table\", h.row_data)).* FROM (
            SELECT DISTINCT ON (v.source_key) v.source_key,v.row_data,v.deleted
            FROM public.platform_user_history v JOIN public.platform_user_snapshot_batches b ON b.transaction_id=v.transaction_id
            WHERE v.table_name='$table' AND b.published_at<='$time'::timestamptz
            ORDER BY v.source_key,v.id DESC
        ) h WHERE NOT h.deleted$observed";
    }

    /** The two directory traversals use recursive SQL rather than the query grammar. */
    public function sql(string $sql): string
    {
        if ($this->at === null) {
            return $sql;
        }

        return preg_replace_callback('/\b(FROM|JOIN)\s+(promotion_members|users|user_profiles)\b(?:\s+(?!WHERE\b|JOIN\b|LEFT\b|RIGHT\b|INNER\b|ON\b|UNION\b|ORDER\b|GROUP\b)([a-z][a-z0-9_]*))?/i',
            fn ($m) => $m[1].' ('.$this->relation($m[2]).') AS '.($m[3] ?? $m[2]), $sql);
    }
}
