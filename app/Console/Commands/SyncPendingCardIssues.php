<?php

namespace App\Console\Commands;

use App\Domain\Card\Models\CardIssueOrder;
use App\Jobs\SyncPendingCardIssue;
use Illuminate\Console\Command;

final class SyncPendingCardIssues extends Command
{
    protected $signature = 'cards:sync-pending-issues {--tenant= : Restrict to one company}';
    protected $description = 'Query original pending card issue operations without resubmitting card openings';

    public function handle(): int
    {
        $count = 0;
        CardIssueOrder::query()
            ->when($this->option('tenant'), fn ($query, $tenant) => $query->where('tenant_id', $tenant))
            ->whereIn('provider', ['U1', 'PHOTONPAY'])
            ->whereIn('status', ['PROCESSING', 'UNKNOWN'])
            ->whereNotNull('provider_request_id')
            ->where('provider_request_id', '<>', '')
            ->where('created_at', '<=', now()->subSeconds(30))
            ->chunkById(100, function ($orders) use (&$count): void {
                foreach ($orders as $order) {
                    SyncPendingCardIssue::dispatch($order->tenant_id, $order->id)->afterCommit();
                    $count++;
                }
            });
        $this->info('Dispatched '.$count.' pending card issue synchronizations.');
        return self::SUCCESS;
    }
}
