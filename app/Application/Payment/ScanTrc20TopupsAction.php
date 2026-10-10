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
    public const RUNTIME_REVISION = '2026-10-10-direct-pending-windows';

    public function __construct(private BlockchainGatewayInterface $gateway, private ProcessIncomingTrc20TransferAction $process, private ExpireTrc20TopupsAction $expire) {}

    /** @return array<string,int> */
    public function execute(): array
    {
        $address = app(TronDepositConfiguration::class)->address();
        if (! $this->gateway->available() || $address === '') {
            throw new DomainException('BLOCKCHAIN_MONITOR_UNAVAILABLE', 'Blockchain monitoring is unavailable.', 503);
        }
        $total = ['CREDITED' => 0, 'PAID' => 0, 'CONFIRMING' => 0, 'UNMATCHED' => 0, 'WINDOWS' => 0];
        $failure = null;
        $cutoff = now()->subHour()->toDateTimeImmutable();
        foreach (app(TronDepositConfiguration::class)->addresses() as $receivingAddress) {
            try {
                foreach ($this->scanAddress($receivingAddress, $cutoff) as $status => $count) {
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
     * Read recent unfinished orders directly through the confirmed head. Saved
     * progress must never delay new orders behind historical empty windows.
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
        // Keep keyset pagination while grouping at most 100 orders in memory.
        // Shared-address orders often overlap: discover/verify each grouped
        // interval once (at most an hour of merged history), then retain the
        // original tenant/order processing scope and individual validity limits.
        foreach ($orders->chunk(100) as $chunk) {
            $windows = [];
            foreach ($chunk->sortBy('created_at') as $order) {
                $from = $order->created_at->toDateTimeImmutable();
                $to = min($through, $order->expires_at->toDateTimeImmutable());
                if ($to <= $from) {
                    continue;
                }
                $last = array_key_last($windows);
                if ($last !== null && $from <= $windows[$last]['to']
                    && $to <= max($windows[$last]['to'], $windows[$last]['from']->modify('+1 hour'))) {
                    $windows[$last]['to'] = max($windows[$last]['to'], $to);
                    $windows[$last]['orders'][] = $order;
                } else {
                    $windows[] = ['from' => $from, 'to' => $to, 'orders' => [$order]];
                }
            }
            foreach ($windows as $window) {
                $transfers = $this->gateway->between($address, $window['from'], $window['to']);
                $counts['WINDOWS']++;
                foreach ($transfers as $transfer) {
                    foreach ($window['orders'] as $order) {
                        // A merged query must never extend an order's validity.
                        if ($transfer->occurredAt < $order->created_at || $transfer->occurredAt > $order->expires_at) {
                            continue;
                        }
                        $result = $this->process->execute($transfer, $order->tenant_id, $order->id, $start, unfinishedOnly: true);
                        $counts[$result] = ($counts[$result] ?? 0) + 1;
                    }
                }
            }
        }

        return $counts;
    }

    private function scanAddress(string $address, DateTimeImmutable $cutoff): array
    {
        $counts = ['CREDITED' => 0, 'PAID' => 0, 'CONFIRMING' => 0, 'UNMATCHED' => 0, 'WINDOWS' => 0];
        // A rolling creation-time boundary, shared by every address in this run.
        // Older unresolved orders remain intact for explicit administrator handling.
        $oldest = WalletTopupOrder::query()->where('payment_rail', 'TRC20_SHARED')
            ->where('network_code', 'TRON')->where('deposit_address', $address)
            ->whereIn('status', ['PENDING', 'PROCESSING', 'PAID'])
            ->where('created_at', '>=', $cutoff->format('Y-m-d H:i:s.uP'))->min('created_at');
        if ($oldest === null) {
            return $counts;
        }
        $start = $cutoff;
        $cursor = null;
        $to = null;
        if ($this->gateway instanceof Trc20ChainReader) {
            $id = hash('sha256', 'TRON:USDT:'.$address);
            $cursor = DB::table('trc20_scan_cursors')->where('id', $id)->first();
            if (! $cursor) {
                // Bootstrap only recent unfinished orders; never replay older history.
                $initial = new DateTimeImmutable($oldest);
                DB::table('trc20_scan_cursors')->insertOrIgnore([
                    'id' => $id,
                    'started_at' => $initial->format('Y-m-d H:i:s.uP'),
                    'scanned_through' => $initial->format('Y-m-d H:i:s.uP'),
                ]);
                $cursor = DB::table('trc20_scan_cursors')->where('id', $id)->first();
            }
            $start = max($cutoff, new DateTimeImmutable($cursor->started_at));
            $to = $this->gateway->confirmedThrough();
            if ($to <= $start) {
                return $counts;
            }
            $counts = $this->recheckPending($address, $start, $to, $counts);
            // Every eligible order window was queried above; no separate historical walk.
            $transfers = [];
        } else {
            $transfers = $this->gateway->listIncomingUsdtTrc20Transfers($address);
        }
        foreach ($transfers as $transfer) {
            $result = $this->process->execute($transfer, notBefore: $start, unfinishedOnly: true);
            $counts[$result] = ($counts[$result] ?? 0) + 1;
        }
        // Full successful window only. Replays after crash/concurrent scans are Ledger-idempotent.
        // No HTTP occurs inside a DB transaction, and uncertainty never advances the checkpoint.
        if ($cursor && $to >= new DateTimeImmutable($cursor->scanned_through)
            && $counts['CONFIRMING'] === 0 && $counts['PAID'] === 0) {
            DB::table('trc20_scan_cursors')->where('id', $cursor->id)->where('scanned_through', $cursor->scanned_through)
                ->update(['scanned_through' => $to->format('Y-m-d H:i:s.uP')]);
            $this->expire->execute($to, $start, $address);
        }

        return $counts;
    }
}
