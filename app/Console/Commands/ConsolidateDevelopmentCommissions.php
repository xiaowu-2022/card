<?php

namespace App\Console\Commands;

use App\Application\Promotion\ConsolidateDevelopmentCommission;
use Illuminate\Console\Command;
use App\Domain\Ledger\ValueObjects\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ConsolidateDevelopmentCommissions extends Command
{
    protected $signature = 'promotion:consolidate-development-commissions {--tenant=} {--execute : Post the previewed balances through LedgerWriter}';
    protected $description = 'Preview or consolidate isolated development commission balances into USDT wallets once.';

    public function handle(ConsolidateDevelopmentCommission $action): int
    {
        $action->assertDevelopment();
        $tenant = $this->option('tenant');
        if ($tenant && ! Str::isUuid($tenant)) { return self::INVALID; }
        $query = DB::table('ledger_accounts')->where('account_type', 'USER_COMMISSION')->where('asset_code', 'USDT')->where('balance', '>', 0)
            ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant));
        $this->info(($this->option('execute') ? 'Execute' : 'Preview').': '.$query->count().' accounts; '.Money::of((string) (clone $query)->sum('balance'), 'USDT')->amount().' USDT');
        foreach ($query->orderBy('id')->lazyById(100) as $account) {
            $this->line($account->tenant_id.' / '.$account->user_id.' / '.Money::of($account->balance, 'USDT')->amount().' USDT');
            if ($this->option('execute')) { $action->execute($account->tenant_id, $account->id); }
        }
        return self::SUCCESS;
    }
}
