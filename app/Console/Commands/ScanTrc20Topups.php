<?php

namespace App\Console\Commands;

use App\Application\Payment\ExpireTrc20TopupsAction;
use App\Application\Payment\ProcessIncomingTrc20TransferAction;
use App\Domain\Withdrawal\Contracts\BlockchainGatewayInterface;
use Illuminate\Console\Command;

final class ScanTrc20Topups extends Command
{
    protected $signature = 'topups:scan-trc20';

    protected $description = 'Scan the configured shared TRC20 address and settle exact USDT top-ups';

    public function handle(
        BlockchainGatewayInterface $gateway,
        ProcessIncomingTrc20TransferAction $process,
        ExpireTrc20TopupsAction $expire,
    ): int {
        if (! $gateway->available()) {
            $this->error('Blockchain monitoring is unavailable.');

            return self::FAILURE;
        }

        $address = (string) config('payment.trc20_deposit_address');
        if ($address === '') {
            $this->error('The shared TRC20 deposit address is not configured.');

            return self::FAILURE;
        }

        $transfers = $gateway->listIncomingUsdtTrc20Transfers($address);
        $results = ['CREDITED' => 0, 'PAID' => 0, 'CONFIRMING' => 0, 'UNMATCHED' => 0];
        foreach ($transfers as $transfer) {
            $result = $process->execute($transfer);
            $results[$result] = ($results[$result] ?? 0) + 1;
        }
        $expired = $expire->execute();
        $this->info('Scanned '.count($transfers)." transfer(s): {$results['CREDITED']} credited, {$results['CONFIRMING']} confirming, {$results['UNMATCHED']} unmatched; {$expired} expired.");

        return self::SUCCESS;
    }
}
