<?php

namespace App\Application\Card;

use Illuminate\Support\Facades\DB;

final class AdminCardLoadsQuery
{
    public function get(?string $tenantId, ?string $search = null)
    {
        return DB::table('card_management_orders as o')
            ->join('users as u', fn ($j) => $j->on('u.id', '=', 'o.user_id')->on('u.tenant_id', '=', 'o.tenant_id'))
            ->join('tenants as t', 't.id', '=', 'o.tenant_id')
            ->join('user_cards as c', fn ($j) => $j->on('c.id', '=', 'o.card_id')->on('c.tenant_id', '=', 'o.tenant_id'))
            ->where('o.kind', 'LOAD')->when($tenantId, fn ($q) => $q->where('o.tenant_id', $tenantId))
            ->when($search, fn ($q) => $q->where(function ($q) use ($search): void {
                $pattern = '%'.addcslashes($search, '%_').'%';
                $q->where('u.email', 'ilike', $pattern)->orWhere('u.account_id', 'like', $pattern)->orWhere('u.phone', 'like', $pattern);
            }))
            ->selectRaw("(o.status IN ('QUOTING','QUOTED') AND o.hold_entry_id IS NULL AND o.settlement_entry_id IS NULL AND o.release_entry_id IS NULL AND o.provider_called_at IS NULL) AS \"canVoid\"")
            ->addSelect(['o.id', 'o.tenant_id as tenantId', 'o.card_id as cardId', 't.name as companyName', 'u.email as userEmail', 'c.masked_pan as maskedPan',
                'o.manual_funding_amount as manualFundingAmount', 'o.amount', 'o.requested_amount as requestedAmount', 'o.overflow_amount as overflowAmount',
                'o.arrival_amount as arrivalAmount', 'o.debit_amount as debitAmount', 'o.fee_amount as feeAmount',
                'o.status', 'o.created_at as createdAt'])
            ->orderByDesc('o.created_at')->orderBy('o.id')->paginate(20, ['*'], 'loads_page')->withQueryString();
    }
}
