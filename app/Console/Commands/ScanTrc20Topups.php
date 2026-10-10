<?php

namespace App\Console\Commands;

use App\Application\Assets\TronDepositConfiguration;
use App\Application\Payment\ExpireTrc20TopupsAction;
use App\Application\Payment\ScanTrc20TopupsAction;
use App\Domain\Payment\Contracts\Trc20ChainReader;
use App\Domain\Withdrawal\Contracts\BlockchainGatewayInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class ScanTrc20Topups extends Command
{
    protected $signature = 'topups:scan-trc20';

    protected $description = 'Scan the configured shared TRC20 address and settle exact USDT top-ups';

    public function handle(
        BlockchainGatewayInterface $gateway,
        ScanTrc20TopupsAction $scan,
        ExpireTrc20TopupsAction $expire,
    ): int {
        if (! $gateway->available()) {
            $this->error('Blockchain monitoring is unavailable.');

            return self::FAILURE;
        }

        $address = app(TronDepositConfiguration::class)->address();
        if ($address === '') {
            $this->error('The shared TRC20 deposit address is not configured.');

            return self::FAILURE;
        }

        $results = $scan->execute();
        // Live checkpoint may intentionally lag. Do not expire/release unseen reservations here.
        $expired = $gateway instanceof Trc20ChainReader ? 0 : $expire->execute(notBefore: now()->subHour()->toDateTimeImmutable());
        Log::info('TRC20 automatic scan completed', [
            'scanner_revision' => ScanTrc20TopupsAction::RUNTIME_REVISION,
            'windows_read' => $results['WINDOWS'],
            'credited' => $results['CREDITED'], 'confirming' => $results['CONFIRMING'],
            'paid_pending_settlement' => $results['PAID'],
        ]);
        $this->info("{$results['CREDITED']} credited, {$results['CONFIRMING']} confirming, {$results['UNMATCHED']} unmatched; {$expired} expired.");

        return self::SUCCESS;
    }
}
