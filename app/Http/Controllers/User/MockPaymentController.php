<?php

namespace App\Http\Controllers\User;

use App\Application\Payment\PersistPaymentProviderEventAction;
use App\Domain\Payment\Models\PaymentProviderTransaction;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class MockPaymentController extends Controller
{
    public function show(string $providerRequest, TenantContext $context): Response
    {
        $transaction = $this->transaction($context, $providerRequest);

        return Inertia::render('user/MockPayment', ['payment' => [
            'providerRequestId' => $transaction->provider_request_id,
            'amount' => $transaction->amount,
            'asset' => $transaction->asset_code,
        ]]);
    }

    public function complete(string $providerRequest, TenantContext $context, PersistPaymentProviderEventAction $action): RedirectResponse
    {
        $transaction = $this->transaction($context, $providerRequest);
        $payload = json_encode([
            'event_id' => 'mock_event_'.bin2hex(random_bytes(12)), 'event_type' => 'PAYMENT_SUCCEEDED',
            'provider_transaction_id' => $transaction->provider_transaction_id,
            'provider_request_id' => $transaction->provider_request_id, 'status' => 'SUCCEEDED',
            'amount' => $transaction->amount, 'asset' => $transaction->asset_code,
        ], JSON_THROW_ON_ERROR);
        $request = Request::create('/webhooks/payment/mock', 'POST', [], [], [], [], $payload);
        $request->headers->set('X-Mock-Signature', hash_hmac('sha256', $payload, (string) config('payment.mock_webhook_secret')));
        $action->execute('mock', $request);

        return redirect()->route('user.topups.return', ['topup' => $transaction->wallet_topup_order_id]);
    }

    private function transaction(TenantContext $context, string $providerRequest): PaymentProviderTransaction
    {
        /** @var User $user */
        $user = Auth::guard('tenant_user')->user();

        return PaymentProviderTransaction::query()->where('payment_provider_transactions.tenant_id', $context->id())
            ->where('provider_request_id', $providerRequest)
            ->whereHas('order', fn ($query) => $query->where('user_id', $user->id))->firstOrFail();
    }
}
