<?php

namespace App\Http\Controllers\User;

use App\Application\Promotion\PaidPromotionPurchase;
use App\Application\Promotion\PaidPromotionQuery;
use App\Application\Promotion\PromotionRanks;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class PaidPromotionController extends Controller
{
    public function show(Request $request, TenantContext $context, PaidPromotionQuery $query)
    {
        $data = $request->validate(['order' => ['nullable', 'uuid'], 'claims_page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $user = $request->user('tenant_user')->id;
        $order = isset($data['order']) ? $query->scopedOrder($context->id(), $user, $data['order']) : null;

        return Inertia::render('user/PaidPromotion', ['paid' => $query->execute($context->id(), $user, (int) ($data['claims_page'] ?? 1)), 'quote' => $order]);
    }

    public function details(Request $request, TenantContext $context, PaidPromotionQuery $query)
    {
        $v = $request->validate(['kind' => ['required', Rule::in(['ANNUAL', 'ACTIVATION'])], 'rank' => ['required', 'integer', Rule::in(PromotionRanks::forTenant($context->id()))], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);

        return Inertia::render('user/PromotionRewardDetails', ['details' => $query->details($context->id(), $request->user('tenant_user')->id, $v['kind'], (int) $v['rank'], (int) ($v['page'] ?? 1))]);
    }

    public function quote(Request $request, TenantContext $context, PaidPromotionPurchase $purchase)
    {
        $v = $request->validate(['level_id' => ['required', 'uuid'], 'request_id' => ['required', 'uuid'], 'amount' => ['prohibited'], 'tenant_id' => ['prohibited'], 'user_id' => ['prohibited']]);
        $o = $purchase->quote($context->id(), $request->user('tenant_user')->id, $v['level_id'], $v['request_id']);

        return redirect('/promotion/membership?order='.$o->id);
    }

    public function confirm(Request $request, TenantContext $context, PaidPromotionPurchase $purchase, string $order)
    {
        $request->validate(['current_password' => ['required', 'current_password:tenant_user'], 'confirmed' => ['required', 'accepted'], 'amount' => ['prohibited']]);
        $purchase->confirm($context->id(), $request->user('tenant_user')->id, $order);

        return redirect('/promotion/membership?order='.$order)->with('success', 'Promotion payment completed.');
    }
}
