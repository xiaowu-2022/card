<?php

namespace App\Application\Payment;

use App\Application\Assets\TronDepositConfiguration;
use App\Domain\Payment\Contracts\Trc20ChainReader;
use App\Domain\Payment\Models\WalletTopupOrder;
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
        $address = app(TronDepositConfiguration::class)->address();
        if (! $this->gateway->available() || $address === '') {
            throw new DomainException('BLOCKCHAIN_MONITOR_UNAVAILABLE', 'Blockchain monitoring is unavailable.', 503);
        }
        $total = ['CREDITED' => 0, 'PAID' => 0, 'CONFIRMING' => 0, 'UNMATCHED' => 0];
        $failure = null;
        foreach (app(TronDepositConfiguration::class)->addresses() as $receivingAddress) {
            try {
                foreach ($this->scanAddress($receivingAddress) as $status => $count) {
                    $total[$status] = ($total[$status] ?? 0) + $count;
                }
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }
        if ($failure) {
            throw $failure;
        }

        return $total;
    }

    /**
     * Public transaction indexes can lag behind solidified receipts. A successful
     * empty index response is not proof that an unfinished order received nothing.
     * Revisit only that order's already-scanned validity interval, without rewinding
     * the forward cursor or replaying completed/pre-boundary orders.
     *
     * @param  array<string,int>  $counts
     * @return array<string,int>
     */
    private function recheckPending(string $address, DateTimeImmutable $start, DateTimeImmutable $through, array $counts): array
    {
        $orders = WalletTopupOrder::query()->where('payment_rail', 'TRC20_SHARED')
            ->where('network_code', 'TRON')->where('deposit_address', $address)
            ->whereIn('status', ['PENDING', 'PROCESSING', 'PAID'])
            ->where('created_at', '>=', $start->format('Y-m-d H:i:s.uP'))
            ->where('created_at', '<', $through->format('Y-m-d H:i:s.uP'))
            ->lazyById(100);
        foreach ($orders as $order) {
            $from = $order->created_at->toDateTimeImmutable();
            $to = min($through, $order->expires_at->toDateTimeImmutable());
            if ($to <= $from) {
                continue;
            }
            foreach ($this->gateway->between($address, $from, $to) as $transfer) {
                $result = $this->process->execute($transfer, $order->tenant_id, $order->id, $start);
                $counts[$result] = ($counts[$result] ?? 0) + 1;
            }
        }

        return $counts;
    }

    private function scanAddress(string $address): array
    {
        $counts = ['CREDITED' => 0, 'PAID' => 0, 'CONFIRMING' => 0, 'UNMATCHED' => 0];
        $start = null;
        $cursor = null;
        $to = null;
        if ($this->gateway instanceof Trc20ChainReader) {
            $id = hash('sha256', 'TRON:USDT:'.$address);
            $cursor = DB::table('trc20_scan_cursors')->where('id', $id)->first();
            if (! $cursor) {
                // Bootstrap only unfinished orders, never completed/expired history.
                // Persist once: later configuration or orders must never rewind progress.
                $oldest = WalletTopupOrder::query()->where('payment_rail', 'TRC20_SHARED')
                    ->where('network_code', 'TRON')->where('deposit_address', $address)
                    ->whereIn('status', ['PENDING', 'PROCESSING', 'PAID'])->min('created_at');
                if ($oldest === null) {
                    return $counts;
                }
                $initial = new DateTimeImmutable($oldest);
                DB::table('trc20_scan_cursors')->insertOrIgnore([
                    'id' => $id,
                    'started_at' => $initial->format('Y-m-d H:i:s.uP'),
                    'scanned_through' => $initial->format('Y-m-d H:i:s.uP'),
                ]);
                $cursor = DB::table('trc20_scan_cursors')->where('id', $id)->first();
            }
            $start = new DateTimeImmutable($cursor->started_at);
            $from = new DateTimeImmutable($cursor->scanned_through);
            $safe = $this->gateway->confirmedThrough();
            $counts = $this->recheckPending($address, $start, min($from, $safe), $counts);
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
