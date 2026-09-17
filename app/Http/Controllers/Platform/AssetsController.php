<?php

namespace App\Http\Controllers\Platform;

use App\Application\Admin\FinancialOperationQuery;
use App\Application\Assets\ConfigureAssetsAction;
use App\Application\Assets\DepositAssetsAction;
use App\Application\Assets\MarketPrices;
use App\Application\Assets\RecheckAssetDeposit;
use App\Application\Assets\WithdrawAssetsAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Assets\AssetDepositOrder;
use App\Domain\Assets\AssetRail;
use App\Domain\Assets\AssetWithdrawalOrder;
use App\Domain\Assets\ChainConnection;
use App\Domain\Assets\ChainObservation;
use App\Domain\Assets\CompanyRail;
use App\Domain\Assets\ExchangePolicy;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Withdrawal\Services\WithdrawalAddressProtector;
use App\Http\Controllers\Controller;
use App\Infrastructure\Assets\PublicChainNodes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

final class AssetsController extends Controller
{
    public function settings(Request $r)
    {
        $r->validate(['company' => 'nullable|uuid']);
        $tenant = $r->filled('company') ? Tenant::whereKey($r->input('company'))->firstOrFail() : null;

        return Inertia::render('platform/AssetSettings', [
            'companies' => Tenant::orderBy('name')->get(['id', 'name'])->toArray(), 'company' => $tenant?->id,
            'market' => app(MarketPrices::class)->configuration(),
            'networks' => ChainConnection::all()->map(fn ($c) => ['network' => $c->network, 'enabled' => $c->enabled, 'rpc_url' => $c->rpc_url ?: PublicChainNodes::URLS[$c->network], 'use_public' => ! $c->rpc_url || $c->rpc_url === PublicChainNodes::URLS[$c->network], 'start_height' => $c->start_height, 'next_height' => $c->next_height, 'confirmations' => $c->confirmations, 'configured' => filled($c->credential)])->all(),
            'rails' => AssetRail::all()->map(fn ($a) => ['code' => $a->code, 'asset' => $a->asset_code, 'network' => $a->network, 'address' => $a->deposit_address ?? '', 'enabled' => $a->enabled])->all(),
            'companyRails' => $tenant ? CompanyRail::where('tenant_id', $tenant->id)->get(['rail_code', 'deposit_enabled', 'withdrawal_enabled', 'minimum_deposit', 'withdrawal_fee_percent'])->toArray() : [],
            'policies' => $tenant ? ExchangePolicy::where('tenant_id', $tenant->id)->get(['asset_code', 'enabled'])->toArray() : [],
        ]);
    }

    public function save(Request $r, ConfigureAssetsAction $action, ?Tenant $tenant = null)
    {
        $action->execute($r->user('platform_admin'), $r->all(), $tenant);

        return back()->with('success', match ($r->input('kind')) {
            'network-test' => 'Network connection verified.',
            'market-refresh' => 'Platform rates updated.',
            default => 'Asset configuration saved.',
        });
    }

    public function deposits(Request $r)
    {
        return Inertia::render('platform/AssetOrders', ['mode' => 'deposit', 'orders' => AssetDepositOrder::latest()->paginate(25)->through(fn ($o) => $this->dto($o)), 'observations' => ChainObservation::where('status', 'REQUIRES_REVIEW')->latest()->limit(20)->get(['network', 'event_id', 'rail_code', 'amount', 'occurred_at'])->toArray()]);
    }

    public function withdrawals(Request $r)
    {
        return Inertia::render('platform/AssetOrders', ['mode' => 'withdrawal', 'orders' => AssetWithdrawalOrder::latest()->paginate(25)->through(fn ($o) => $this->dto($o)), 'observations' => []]);
    }

    private function dto($o): array
    {
        return ['operations' => app(FinancialOperationQuery::class)->forOrder($o->tenant_id, $o instanceof AssetWithdrawalOrder ? 'asset_withdrawal_order' : 'asset_deposit_order', $o->id), 'id' => $o->id, 'tenant_id' => $o->tenant_id, 'company' => Tenant::findOrFail($o->tenant_id)->name, 'user_id' => $o->user_id, 'asset' => $o->asset_code, 'network' => $o->network, 'amount' => $o->amount, 'status' => $o->status, 'created_at' => $o->created_at->toIso8601String(), 'operator' => ($actorId = ($o->manual_confirmed_by ?? $o->reviewed_by)) ? AdminUser::find($actorId)?->name : null, 'operated_at' => ($o->manual_confirmed_at ?? $o->reviewed_at)?->toIso8601String(), 'fee' => $o instanceof AssetWithdrawalOrder ? $o->fee_amount : null, 'address' => $o instanceof AssetWithdrawalOrder ? app(WithdrawalAddressProtector::class)->mask($o->address) : $o->address, 'tx_hash' => $o->submitted_tx_hash ?? ''];
    }

    public function confirm(Request $r, Tenant $tenant, string $order, DepositAssetsAction $action)
    {
        $d = $r->validate(['request_id' => 'required|uuid', 'confirmed' => 'required|accepted']);
        $action->manual($tenant->id, $order, $r->user('platform_admin'), $d['request_id'], true);

        return back();
    }

    public function recheck(Request $r, Tenant $tenant, string $order, RecheckAssetDeposit $action)
    {
        $d = $r->validate(['request_id' => 'required|uuid', 'tx_hash' => 'required|string|max:128']);
        $action->execute($tenant->id, $order, $r->user('platform_admin'), $d['tx_hash'], $d['request_id']);

        return back();
    }

    public function review(Request $r, Tenant $tenant, string $order, WithdrawAssetsAction $action)
    {
        $d = $r->validate(['approve' => 'required|boolean', 'confirmed' => 'required|accepted']);
        $action->review($tenant->id, $order, $r->user('platform_admin'), $d['approve']);

        return back();
    }

    public function verify(Request $r, Tenant $tenant, string $order, WithdrawAssetsAction $action)
    {
        $d = $r->validate(['request_id' => 'required|uuid', 'tx_hash' => 'required|string|max:128', 'confirmed' => 'required|accepted']);
        $action->verify($tenant->id, $order, $r->user('platform_admin'), $d['tx_hash'], $d['request_id']);

        return back();
    }

    public function reveal(Request $r, Tenant $tenant, string $order)
    {
        $d = $r->validate(['password' => 'required|string']);
        $actor = $r->user('platform_admin');
        abort_unless(Hash::check($d['password'], $actor->password), 403);
        $o = AssetWithdrawalOrder::where('tenant_id', $tenant->id)->whereKey($order)->firstOrFail();
        app(AuditLogger::class)->record($tenant->id, 'ADMIN', $actor->id, 'ASSET_WITHDRAWAL_ADDRESS_REVEALED', 'asset_withdrawal_order', $o->id);

        return response()->json(['address' => $o->address], 200, ['Cache-Control' => 'no-store, private']);
    }
}
