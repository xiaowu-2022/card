<?php

namespace App\Console\Commands;

use App\Application\Inbox\InboxDelivery;
use App\Application\Inbox\InboxWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecoverInbox extends Command
{
    protected $signature = 'messages:recover {--tenant=}';

    protected $description = 'Deliver pending inbox messages; never reprocess financial operations';

    public function handle(InboxDelivery $delivery, InboxWriter $writer): int
    {
        $tenant = $this->option('tenant');
        if ($tenant && ! Str::isUuid($tenant)) {
            $this->error('Invalid tenant UUID.');

            return self::FAILURE;
        }
        $start = DB::table('inbox_installation')->where('id', 1)->value('started_at');
        DB::table('wealth_orders as w')->where('w.status', 'ACTIVE')->where('w.maturity_policy', 'MANUAL_REDEEM_RENEW')
            ->where('w.matures_at', '>=', $start)->where('w.matures_at', '<=', now())->where('w.redeem_before', '>', now())
            ->when($tenant, fn ($q) => $q->where('w.tenant_id', $tenant))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('inbox_events as e')->whereColumn('e.tenant_id', 'w.tenant_id')->whereColumn('e.user_id', 'w.user_id')->whereRaw("e.event_key = 'wealth_mature:' || w.id::text"))
            ->orderBy('w.id')->limit(500)->get()->each(function ($r) use ($writer) {
                DB::transaction(function () use ($r, $writer) {
                    $order = DB::table('wealth_orders')->where('tenant_id', $r->tenant_id)->where('id', $r->id)->lockForUpdate()->first();
                    if ($order->status !== 'ACTIVE' || now()->greaterThanOrEqualTo($order->redeem_before)) {
                        return;
                    }
                    $writer->record($r->tenant_id, $r->user_id, 'wealth_mature:'.$r->id, 'wealth_mature', ['amount' => $r->principal, 'asset' => $r->asset_code], '/wealth/orders/'.$r->id, $r->matures_at);
                });
            });
        $this->info('Delivered: '.$delivery->recover($tenant));

        return self::SUCCESS;
    }
}
