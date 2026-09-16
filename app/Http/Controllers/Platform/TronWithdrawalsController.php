<?php

namespace App\Http\Controllers\Platform;

use App\Application\Admin\FinancialOperationQuery;
use App\Application\Withdrawal\ApproveWithdrawalAction;
use App\Application\Withdrawal\RejectWithdrawalAction;
use App\Application\Withdrawal\RevealWithdrawalAddressAction;
use App\Application\Withdrawal\VerifyWithdrawalTransactionAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Withdrawal\Models\WithdrawalOrder;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

/** SaaS access to the existing TRON contract; no alternate settlement path. */
final class TronWithdrawalsController extends Controller
{
    public function index()
    {
        return Inertia::render('platform/AssetOrders', ['mode' => 'withdrawal', 'observations' => [], 'orders' => WithdrawalOrder::with('destination')->latest()->paginate(25)->through(fn ($o) => [
            'operations' => app(FinancialOperationQuery::class)->forOrder($o->tenant_id, 'withdrawal_order', $o->id), 'legacy' => true, 'id' => $o->id, 'tenant_id' => $o->tenant_id, 'company' => Tenant::findOrFail($o->tenant_id)->name, 'user_id' => $o->user_id, 'asset' => $o->asset_code, 'network' => $o->network_code, 'amount' => $o->amount, 'fee' => $o->fee_amount,
            'status' => match ($o->status->value) {
                'SUCCEEDED' => 'COMPLETED','VERIFYING' => 'UNKNOWN',default => $o->status->value
            }, 'created_at' => $o->requested_at->toIso8601String(), 'address' => $o->destination->masked_address, 'tx_hash' => $o->submitted_tx_hash ?? '',
            'operator' => $o->reviewed_by_admin_user_id ? AdminUser::find($o->reviewed_by_admin_user_id)?->name : null, 'operated_at' => $o->reviewed_at?->toIso8601String(),
        ])]);
    }

    public function review(Request $r, Tenant $tenant, string $order, ApproveWithdrawalAction $approve, RejectWithdrawalAction $reject)
    {
        $d = $r->validate(['approve' => 'required|boolean', 'confirmed' => 'required|accepted', 'reason' => 'required_if:approve,false|nullable|string|max:240']);
        $actor = $r->user('platform_admin');
        if ($d['approve']) {
            $approve->execute($tenant->id, $order, $actor);
        } else {
            $reject->execute($tenant->id, $order, $actor, $d['reason']);
        }

        return back();
    }

    public function verify(Request $r, Tenant $tenant, string $order, VerifyWithdrawalTransactionAction $verify)
    {
        $d = $r->validate(['tx_hash' => 'required|string|regex:/^[a-f0-9]{64}$/i', 'confirmed' => 'required|accepted', 'request_id' => 'required|uuid']);
        $verify->execute($tenant->id, $order, $r->user('platform_admin'), $d['tx_hash'], $d['request_id']);

        return back();
    }

    public function reveal(Request $r, Tenant $tenant, string $order, RevealWithdrawalAddressAction $reveal)
    {
        $d = $r->validate(['password' => 'required|string']);
        $actor = $r->user('platform_admin');
        abort_unless(Hash::check($d['password'], $actor->fresh()->password), 403);

        return response()->json(['address' => $reveal->execute($tenant->id, $order, $actor)], 200, ['Cache-Control' => 'no-store, private']);
    }
}
