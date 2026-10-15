<?php

namespace App\Jobs;

use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Infrastructure\Providers\Card\PhotonPayMerchantReport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class RefreshPhotonPayAccountReport implements ShouldQueue
{
    use Queueable;
    public int $tries = 5;
    public int $timeout = 60;
    public function __construct(public readonly string $referenceId) {}
    public function backoff(): array { return [10, 30, 60, 120]; }
    public function middleware(): array { return [(new WithoutOverlapping('photonpay-report:'.$this->referenceId))->releaseAfter(10)->expireAfter(90)]; }
    public function handle(PhotonPayMerchantReport $reports): void
    {
        $reference = CardProviderReference::find($this->referenceId);
        if (! $reference || ! $reference->photonpay_reporting_encrypted) return;
        $credentials = $reference->photonpay_reporting_encrypted;
        $started = now();
        $report = $reports->refresh($credentials);
        if ($report['balance'] === null || $report['issuedCardCount'] === null) throw new \RuntimeException('PhotonPay account report unavailable.');
        DB::transaction(function () use ($credentials, $started, $report): void {
            $row = CardProviderReference::whereKey($this->referenceId)->lockForUpdate()->first();
            if (! $row || $row->photonpay_reporting_encrypted !== $credentials) return;
            $previous = $row->photonpay_report_snapshot ? json_decode($row->photonpay_report_snapshot, true, flags: JSON_THROW_ON_ERROR) : [];
            if (isset($previous['startedAt']) && \Carbon\CarbonImmutable::parse($previous['startedAt'])->greaterThan($started)) return;
            DB::table('platform_card_provider_references')->where('id', $row->id)->update(['photonpay_report_snapshot' => json_encode([
                'credentialHash' => hash('sha256', $credentials), 'startedAt' => $started->toIso8601String(), 'report' => $report,
            ], JSON_THROW_ON_ERROR)]);
        });
    }
    public function failed(?\Throwable $error): void
    {
        Log::warning('PhotonPay account report refresh failed', ['reference_id' => $this->referenceId, 'exception_class' => $error ? get_class($error) : null]);
    }
}
