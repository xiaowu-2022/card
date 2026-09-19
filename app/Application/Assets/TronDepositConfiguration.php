<?php

namespace App\Application\Assets;

use App\Domain\Payment\Models\WalletTopupOrder;
use Illuminate\Support\Facades\DB;

final class TronDepositConfiguration
{
    public function address(): string
    {
        // Deployment supplies the initial address; explicit Platform configuration overrides it.
        return (string) (DB::table('asset_tron_settings')->where('id', 1)->value('deposit_address') ?? config('payment.trc20_deposit_address'));
    }

    /** Include immutable order addresses so rotation never strands incoming payments. */
    public function addresses(): array
    {
        return WalletTopupOrder::query()
            ->where('network_code', 'TRON')->whereNotNull('deposit_address')
            ->distinct()->pluck('deposit_address')->push($this->address())
            ->filter()->unique()->values()->all();
    }

    public function accepts(string $address): bool
    {
        return $address === $this->address() || WalletTopupOrder::query()
            ->where('network_code', 'TRON')->where('deposit_address', $address)->exists();
    }
}
