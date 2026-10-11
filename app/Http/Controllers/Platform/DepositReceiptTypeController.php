<?php

namespace App\Http\Controllers\Platform;

use App\Application\Payment\ChangeDepositReceiptTypeAction;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class DepositReceiptTypeController extends Controller
{
    public function __invoke(Request $request, Tenant $tenant, string $source, string $order, ChangeDepositReceiptTypeAction $change)
    {
        $data = $request->validate([
            'receipt_type' => ['required', 'in:ACTUAL,ADVANCE'], 'revision' => ['required', 'integer', 'min:0', 'max:2147483646'],
            'request_id' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted'],
            'amount' => ['prohibited'], 'actual_received_amount' => ['prohibited'], 'asset' => ['prohibited'],
            'tenant_id' => ['prohibited'], 'user_id' => ['prohibited'], 'wallet_id' => ['prohibited'], 'status' => ['prohibited'],
        ]);
        $change->execute($request->user('platform_admin'), $tenant->id, $source, $order, $data['receipt_type'], (int) $data['revision'], $data['request_id'], true);

        return back()->with('success', 'Receipt type updated. Related totals have been refreshed.');
    }
}
