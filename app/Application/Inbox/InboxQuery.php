<?php

namespace App\Application\Inbox;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class InboxQuery
{
    private function receipts(string $tenant, string $user)
    {
        return DB::table('inbox_receipts')->where('tenant_id', $tenant)->where('user_id', $user);
    }

    public function unread(string $tenant, string $user): int
    {
        return $this->receipts($tenant, $user)->whereNull('read_at')->count();
    }

    private function rows(string $tenant, string $user)
    {
        return DB::table('inbox_receipts as r')->join('inbox_events as e', function ($j) {
            $j->on('e.id', '=', 'r.event_id')->on('e.tenant_id', '=', 'r.tenant_id')->on('e.user_id', '=', 'r.user_id');
        })->leftJoin('inbox_broadcasts as b', function ($j) {
            $j->on('b.id', '=', 'e.broadcast_id')->on('b.tenant_id', '=', 'e.tenant_id');
        })->where('r.tenant_id', $tenant)->where('r.user_id', $user)
            ->select('e.id', 'e.kind', 'e.template', 'e.parameters', 'e.href', 'e.occurred_at', 'r.read_at', 'b.title', 'b.body', 'r.id as sequence');
    }

    public function listing(string $tenant, string $user, string $filter)
    {
        return $this->rows($tenant, $user)->when($filter === 'unread', fn ($q) => $q->whereNull('r.read_at'))
            ->when(in_array($filter, ['business', 'platform']), fn ($q) => $q->where('e.kind', strtoupper($filter)))
            ->orderByDesc('e.occurred_at')->orderByDesc('r.id')->paginate(20)->withQueryString()->through(fn ($r) => $this->dto($r));
    }

    public function detail(string $tenant, string $user, string $id): array
    {
        return $this->dto($this->rows($tenant, $user)->where('e.id', $id)->firstOrFail());
    }

    private function dto(object $r): array
    {
        return ['id' => $r->id, 'kind' => $r->kind, 'template' => $r->template, 'parameters' => json_decode($r->parameters, true),
            'href' => $r->href, 'time' => CarbonImmutable::parse($r->occurred_at)->toIso8601String(),
            'readAt' => $r->read_at, 'title' => $r->title, 'body' => $r->body];
    }

    public function read(string $tenant, string $user, ?string $id): void
    {
        $watermark = $id === null ? ($this->receipts($tenant, $user)->max('id') ?? 0) : null;
        DB::transaction(function () use ($tenant, $user, $id, $watermark) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['inbox:'.$tenant.':'.$user]);
            $q = $this->receipts($tenant, $user);
            if ($id !== null) {
                $q->where('event_id', $id);
                abort_unless((clone $q)->exists(), 404);
            } else {
                $q->where('id', '<=', $watermark);
            }
            $q->whereNull('read_at')->update(['read_at' => now()]);
        });
    }
}
