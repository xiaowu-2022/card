<?php

namespace App\Console\Commands;

use App\Application\Assets\TronDepositConfiguration;
use App\Application\Payment\ScanTrc20TopupsAction;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Domain\Withdrawal\Contracts\BlockchainGatewayInterface;
use App\Infrastructure\Providers\Blockchain\TronGridBlockchainGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Production-safe local diagnostics. Never scan, dispatch jobs, or contact a provider. */
final class DiagnoseTrc20Topups extends Command
{
    protected $signature = 'topups:diagnose-trc20';

    protected $description = 'Read TRC20 runtime configuration and pending scan progress without contacting the chain or changing funds';

    public function handle(BlockchainGatewayInterface $gateway): int
    {
        $key = trim((string) config('payment.trongrid_api_key', ''));
        $store = (string) config('cache.default');
        $driver = (string) config('cache.stores.'.$store.'.driver');
        $report = [
            'diagnostic_revision' => '2026-10-10-recent-orders',
            'runtime' => PHP_SAPI,
            'php_version' => PHP_VERSION,
            'environment' => app()->environment(),
            'configuration_cached' => app()->configurationIsCached(),
            'gateway' => $gateway::class,
            'gateway_revision' => defined(TronGridBlockchainGateway::class.'::RUNTIME_REVISION')
                ? constant(TronGridBlockchainGateway::class.'::RUNTIME_REVISION') : 'older-runtime',
            'scanner_revision' => defined(ScanTrc20TopupsAction::class.'::RUNTIME_REVISION')
                ? constant(ScanTrc20TopupsAction::class.'::RUNTIME_REVISION') : 'older-runtime',
            'api_key_configured' => $key !== '',
            'api_key_format_valid' => $key === '' ? null : preg_match('/^[A-Za-z0-9_-]{8,256}$/D', $key) === 1,
            'cache_driver' => $driver,
            'persistent_cache_driver' => ! in_array($driver, ['', 'array', 'null'], true),
            'provider_contacted' => false,
            'provider_authentication_verified' => false,
            'funds_changed' => false,
        ];
        $healthy = true;
        try {
            $report['cooldown_remaining_seconds'] = $gateway instanceof TronGridBlockchainGateway
                && method_exists($gateway, 'cooldownRemainingSeconds') ? $gateway->cooldownRemainingSeconds() : null;
        } catch (Throwable) {
            $report['cooldown_state'] = 'unreadable';
            $healthy = false;
        }
        try {
            // No wallet provisioning, expiry, recovery, or cursor bootstrap is allowed here.
            $report['receiving_address_valid'] = $gateway->available();
            $orders = WalletTopupOrder::query()->where('payment_rail', 'TRC20_SHARED')->where('network_code', 'TRON')
                ->whereIn('status', ['PENDING', 'PROCESSING', 'PAID']);
            $cutoff = now()->subHour();
            $recent = (clone $orders)->where('created_at', '>=', $cutoff);
            $report['automatic_scan_cutoff'] = $cutoff->toIso8601String();
            $report['recent_unfinished_orders'] = (clone $recent)->count();
            $report['outside_automatic_window_orders'] = (clone $orders)->where('created_at', '<', $cutoff)->count();
            $report['oldest_recent_order_at'] = (clone $recent)->min('created_at');
            $report['newest_recent_order_at'] = (clone $recent)->max('created_at');
            $report['unfinished_orders'] = (clone $orders)->count();
            $report['oldest_unfinished_order_at'] = (clone $orders)->min('created_at');
            $report['scan_cursors'] = [];
            foreach (app(TronDepositConfiguration::class)->addresses() as $index => $address) {
                $cursor = DB::table('trc20_scan_cursors')->where('id', hash('sha256', 'TRON:USDT:'.$address))
                    ->first(['started_at', 'scanned_through']);
                $report['scan_cursors'][] = ['address_number' => $index + 1,
                    'started_at' => $cursor?->started_at, 'scanned_through' => $cursor?->scanned_through,
                    'recent_unfinished_orders' => (clone $recent)->where('deposit_address', $address)->count()];
            }
        } catch (Throwable) {
            // Do not print SQL, bindings, addresses, credentials, or exception chains.
            $report['database_state'] = 'unreadable';
            $healthy = false;
        }
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
