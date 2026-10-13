<?php

namespace App\Http\Controllers\Platform;

use App\Application\Payment\ChangeDepositReceiptTypeAction;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class DepositReceiptTypeController extends Controller
{
    public function __invoke(Request $request, Tenant $tenant, string $source, string $order, ChangeDepositReceiptTypeAction $change)
    {
        try {
            $data = $request->validate([
                'receipt_type' => ['required', 'in:ACTUAL,ADVANCE'], 'revision' => ['required', 'integer', 'min:0', 'max:2147483646'],
                'request_id' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted'],
                'amount' => ['prohibited'], 'actual_received_amount' => ['prohibited'], 'asset' => ['prohibited'],
                'tenant_id' => ['prohibited'], 'user_id' => ['prohibited'], 'wallet_id' => ['prohibited'], 'status' => ['prohibited'],
            ]);
            $change->execute($request->user('platform_admin'), $tenant->id, $source, $order, $data['receipt_type'], (int) $data['revision'], $data['request_id'], true);
        } catch (\Throwable $exception) {
            // Record correlation and safe failure categories, never request bodies,
            // receipt amounts, SQL bindings or exception messages.
            Log::warning('Deposit receipt type change failed.', [
                'request_id' => $request->attributes->get('request_id'),
                'actor_id' => $request->user('platform_admin')?->id,
                'tenant_id' => $tenant->id,
                'source' => in_array($source, ['primary', 'asset'], true) ? $source : 'invalid',
                'order_id' => $order,
                'exception_type' => $exception::class,
                'error_code' => $exception instanceof DomainException ? $exception->errorCode : null,
                'validation_fields' => $exception instanceof ValidationException ? array_keys($exception->errors()) : [],
                'sqlstate' => $exception instanceof QueryException ? ($exception->errorInfo[0] ?? null) : null,
            ]);
            throw $exception;
        }

        return back()->with('success', 'Receipt type updated. Related totals have been refreshed.');
    }
}
