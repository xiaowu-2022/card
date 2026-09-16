<?php

namespace App\Http\Controllers\User;

use App\Application\Assets\AssetOverviewQuery;
use App\Application\Assets\DepositAssetsAction;
use App\Application\Assets\ExchangeAssetsAction;
use App\Application\Assets\WithdrawAssetsAction;
use App\Application\Wallet\UserWalletQuery;
use App\Domain\Assets\AssetCatalog;
use App\Domain\Assets\AssetDepositOrder;
use App\Domain\Assets\AssetRail;
use App\Domain\Assets\AssetWithdrawalOrder;
use App\Domain\Assets\ExchangeOrder;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\TenantContext;
use App\Domain\Withdrawal\Services\WithdrawalAddressProtector;
use App\Http\Controllers\Controller;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class AssetsController extends Controller
{
    public function show(Request $request, TenantContext $context, AssetOverviewQuery $query, UserWalletQuery $wallets)
    {
        $user = $request->user('tenant_user');
        $request->validate(['asset' => 'sometimes|in:USDT,USDC,ETH,BTC', 'mode' => 'sometimes|in:deposit,withdrawal,exchange', 'order' => 'sometimes|uuid']);
        $mode = $request->input('mode', 'deposit');
        $result = null;
        if ($id = $request->input('order')) {
            $model = match ($mode) {
                'deposit' => AssetDepositOrder::class,'withdrawal' => AssetWithdrawalOrder::class,'exchange' => ExchangeOrder::class
            };
            $order = $model::query()->where('tenant_id', $context->id())->where('user_id', $user->id)->whereKey($id)->firstOrFail();
            $result = $this->dto($order, $mode);
        }

        return Inertia::render('user/AssetFlow', ['overview' => $query->get($context->id(), $user->id, $wallets->get($context->id(), $user->id)), 'mode' => $mode, 'selectedAsset' => $result['asset'] ?? $request->input('asset', 'USDT'), 'result' => $result]);
    }

    public function history(Request $request, TenantContext $context, string $asset)
    {
        AssetCatalog::assert($asset);
        $request->validate(['page' => 'sometimes|integer|min:1|max:100000']);
        $rows = DB::table('ledger_postings as p')->join('ledger_accounts as a', 'a.id', '=', 'p.ledger_account_id')->join('ledger_entries as e', 'e.id', '=', 'p.ledger_entry_id')
            ->where('p.tenant_id', $context->id())->where('a.tenant_id', $context->id())->where('e.tenant_id', $context->id())->where('a.user_id', $request->user('tenant_user')->id)->where('a.asset_code', $asset)->where('a.account_type', 'USER_AVAILABLE')->orderByDesc('e.posted_at')->orderByDesc('p.id')
            ->select(['p.id', 'p.delta', 'e.posted_at', 'e.event_type'])->paginate(25)->through(fn ($r) => ['id' => $r->id, 'amount' => Money::of($r->delta, $asset)->amount(), 'time' => $r->posted_at, 'kind' => str_starts_with($r->event_type, 'ASSET_EXCHANGE') ? 'Exchange' : (str_starts_with($r->event_type, 'ASSET_DEPOSIT') ? 'Top up' : (str_starts_with($r->event_type, 'ASSET_WITHDRAWAL') ? 'Withdrawal' : 'Account activity'))]);

        return Inertia::render('user/AssetHistory', ['asset' => $asset, 'rows' => $rows]);
    }

    public function store(Request $request, TenantContext $context, DepositAssetsAction $deposits, WithdrawAssetsAction $withdrawals, ExchangeAssetsAction $exchange)
    {
        $data = $request->validate(['mode' => 'required|in:deposit,withdrawal,exchange', 'asset' => 'required|in:USDT,USDC,ETH,BTC', 'rail' => 'required_unless:mode,exchange|string|max:32', 'amount' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,18})?$/'], 'request_id' => 'required|uuid', 'address' => 'exclude_unless:mode,withdrawal|required|string|max:128', 'expected_fee' => ['exclude_unless:mode,withdrawal', 'required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,18})?$/'], 'confirmed' => 'exclude_unless:mode,withdrawal|required|accepted', 'tenant_id' => 'prohibited', 'user_id' => 'prohibited', 'wallet_id' => 'prohibited']);
        $tenant = $context->id();
        $user = $request->user('tenant_user')->id;
        if ($data['mode'] !== 'exchange') {
            abort_unless(AssetRail::query()->whereKey($data['rail'])->where('asset_code', $data['asset'])->exists(), 422);
        }
        $order = match ($data['mode']) {
            'deposit' => $deposits->create($tenant, $user, $data['rail'], $data['amount'], $data['request_id']),
            'withdrawal' => $withdrawals->create($tenant, $user, $data['rail'], $data['amount'], $data['address'], $data['expected_fee'], $data['request_id'], true),
            'exchange' => $exchange->quote($tenant, $user, $data['asset'], $data['amount'], $data['request_id']),
        };

        return redirect('/assets/operate?'.http_build_query(['mode' => $data['mode'], 'asset' => $order->asset_code, 'order' => $order->id]));
    }

    public function confirm(Request $request, TenantContext $context, string $order, ExchangeAssetsAction $exchange)
    {
        $request->validate(['confirmed' => 'required|accepted']);
        $exchange->confirm($context->id(), $request->user('tenant_user')->id, $order);

        return back();
    }

    public function cancel(Request $request, TenantContext $context, string $order, WithdrawAssetsAction $withdrawals)
    {
        $withdrawals->cancel($context->id(), $request->user('tenant_user')->id, $order);

        return back();
    }

    private function dto($o, string $mode): array
    {
        $base = ['id' => $o->id, 'asset' => $o->asset_code, 'amount' => $o->amount, 'state' => match ($o->status) {
            'CREDITED','COMPLETED' => 'Completed','QUOTED' => 'Review exchange','CANCELLED' => 'Cancelled','REJECTED' => 'Rejected','REQUIRES_REVIEW' => 'Under review',default => 'Processing'
        }];

        return $base + match ($mode) {
            'deposit' => ['network' => $o->network, 'address' => $o->address, 'expiresAt' => $o->expires_at->toIso8601String()],
            'withdrawal' => ['network' => $o->network, 'address' => app(WithdrawalAddressProtector::class)->mask($o->address), 'fee' => $o->fee_amount, 'receive' => (string) BigDecimal::of($o->amount)->minus($o->fee_amount), 'canCancel' => $o->status === 'PENDING'],
            'exchange' => ['rate' => $o->rate, 'fee' => $o->fee_amount, 'receive' => $o->receive_amount, 'expiresAt' => $o->expires_at->toIso8601String(), 'canConfirm' => $o->status === 'QUOTED' && $o->expires_at->isFuture()],
        };
    }
}
