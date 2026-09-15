<?php

namespace App\Application\Payment;

use App\Domain\Payment\Contracts\Trc20ChainReader;
use App\Domain\Withdrawal\Contracts\BlockchainGatewayInterface;
use App\Support\Errors\DomainException;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class ScanTrc20TopupsAction
{
    public function __construct(private BlockchainGatewayInterface $gateway, private ProcessIncomingTrc20TransferAction $process, private ExpireTrc20TopupsAction $expire) {}

    /** @return array<string,int> */
    public function execute(): array
    {
        $address = (string) config('payment.trc20_deposit_address');
        if (! $this->gateway->available() || $address === '') {
            throw new DomainException('BLOCKCHAIN_MONITOR_UNAVAILABLE', 'Blockchain monitoring is unavailable.', 503);
        }
        $counts = ['CREDITED' => 0, 'PAID' => 0, 'CONFIRMING' => 0, 'UNMATCHED' => 0];
        $start = null;
        $cursor = null;
        $to = null;
        if ($this->gateway instanceof Trc20ChainReader) {
            $raw = (string) config('payment.trc20_scan_start_at');
            if (! config('payment.trc20_scan_enabled') || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $raw)) {
                throw new DomainException('TRC20_SCAN_NOT_ENABLED', 'Configure an explicit UTC start time before enabling live scans.', 503);
            }
            $start = new DateTimeImmutable($raw);
            if ($start->format('Y-m-d\TH:i:s\Z') !== $raw) {
                throw new DomainException('TRC20_SCAN_START_INVALID', 'Invalid scan start time.');
            }
            $id = hash('sha256', 'TRON:USDT:'.$address);
            DB::table('trc20_scan_cursors')->insertOrIgnore(['id' => $id, 'started_at' => $start, 'scanned_through' => $start]);
            $cursor = DB::table('trc20_scan_cursors')->where('id', $id)->first();
            if (new DateTimeImmutable($cursor->started_at) != $start) {
                throw new DomainException('TRC20_SCAN_START_LOCKED', 'The persisted scan start time cannot be changed.');
            }
            $from = new DateTimeImmutable($cursor->scanned_through);
            $safe = $this->gateway->confirmedThrough();
            if ($safe <= $from) {
                return $counts;
            }
            $to = min($safe, $from->modify('+5 minutes'));
            $transfers = $this->gateway->between($address, $from, $to);
        } else {
            $transfers = $this->gateway->listIncomingUsdtTrc20Transfers($address);
        }
        foreach ($transfers as $transfer) {
            $result = $this->process->execute($transfer, notBefore: $start);
            $counts[$result] = ($counts[$result] ?? 0) + 1;
        }
        // Full successful window only. Replays after crash/concurrent scans are Ledger-idempotent.
        // No HTTP occurs inside a DB transaction, and uncertainty never advances the checkpoint.
        if ($cursor && $counts['CONFIRMING'] === 0 && $counts['PAID'] === 0) {
            DB::table('trc20_scan_cursors')->where('id', $cursor->id)->where('scanned_through', $cursor->scanned_through)
                ->update(['scanned_through' => $to->format('Y-m-d H:i:s.uP')]);
            $this->expire->execute($to, $start, $address);
        }

        return $counts;
    }
}
