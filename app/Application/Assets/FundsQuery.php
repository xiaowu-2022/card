<?php

namespace App\Application\Assets;

use App\Domain\Assets\AssetCatalog;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\ValueObjects\Money;
use Illuminate\Support\Facades\DB;

final class FundsQuery
{
    public function get(string $tenantId, string $userId, string $selectedAsset = 'ALL'): array
    {
        $accounts = LedgerAccount::where('tenant_id', $tenantId)->where('user_id', $userId)
            ->where('account_type', 'USER_AVAILABLE')->whereIn('asset_code', AssetCatalog::ASSETS)->get()->keyBy('asset_code');
        $balances = array_map(fn ($asset) => ['asset' => $asset, 'available' => Money::of($accounts->get($asset)?->balance ?? '0', $asset)->amount()], AssetCatalog::ASSETS);
        $rows = DB::table('ledger_postings as p')->join('ledger_accounts as a', 'a.id', '=', 'p.ledger_account_id')->join('ledger_entries as e', 'e.id', '=', 'p.ledger_entry_id')
            ->where('p.tenant_id', $tenantId)->where('a.tenant_id', $tenantId)->where('e.tenant_id', $tenantId)
            ->where('a.user_id', $userId)->where('a.account_type', 'USER_AVAILABLE')->whereIn('a.asset_code', AssetCatalog::ASSETS);
        if ($selectedAsset !== 'ALL') {
            AssetCatalog::assert($selectedAsset);
            $rows->where('a.asset_code', $selectedAsset);
        }

        return ['selectedAsset' => $selectedAsset, 'balances' => $balances, 'rows' => $rows->orderByDesc('e.posted_at')->orderByDesc('p.id')
            ->select(['p.id', 'p.delta', 'a.asset_code', 'e.posted_at', 'e.event_type'])->paginate(25)->withPath('/funds')->appends(['asset' => $selectedAsset])
            ->through(fn ($r) => ['id' => $r->id, 'asset' => $r->asset_code, 'amount' => Money::of($r->delta, $r->asset_code)->amount(), 'time' => $r->posted_at, 'kind' => AssetActivityLabel::for($r->event_type, $r->delta)])];
    }
}
