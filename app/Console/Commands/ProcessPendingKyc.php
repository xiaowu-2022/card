<?php

namespace App\Console\Commands;

use App\Domain\Kyc\Models\KycApplication;
use Illuminate\Console\Command;

final class ProcessPendingKyc extends Command
{
    protected $signature = 'kyc:process-pending {--limit=20}';
    protected $description = 'Process retained identity submissions without replaying historical approvals';

    public function handle(\App\Application\Kyc\ProcessPendingKyc $processor): int
    {
        $rows = KycApplication::where('review_status', 'PENDING')->whereIn('processing_status', ['QUEUED', 'PROCESSING'])
            ->where('next_processing_at', '<=', now())->orderBy('next_processing_at')->limit(max(1, min(100, (int) $this->option('limit'))))->get(['id', 'tenant_id']);
        foreach ($rows as $row) $processor->execute($row->tenant_id, $row->id);
        return self::SUCCESS;
    }
}
