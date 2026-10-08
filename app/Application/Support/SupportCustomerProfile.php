<?php

namespace App\Application\Support;

use App\Application\Promotion\ManualPromotion;
use App\Domain\User\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class SupportCustomerProfile
{
    public function get(string $tenant, string $actor, string $conversation): array
    {
        $chat = app(SupportUserAgents::class)->conversation($tenant, $actor, $conversation);
        $user = User::where('tenant_id', $tenant)->with('profile')->findOrFail($chat->user_id);
        $parent = DB::table('promotion_members as m')
            ->join('promotion_members as p', fn ($j) => $j->on('p.id', '=', 'm.inviter_id')->on('p.tenant_id', '=', 'm.tenant_id'))
            ->join('users as u', fn ($j) => $j->on('u.id', '=', 'p.user_id')->on('u.tenant_id', '=', 'p.tenant_id'))
            ->leftJoin('user_profiles as profile', fn ($j) => $j->on('profile.user_id', '=', 'u.id')->on('profile.tenant_id', '=', 'u.tenant_id'))
            ->where('m.tenant_id', $tenant)->where('m.user_id', $user->id)
            ->first(['u.account_id', 'u.email', 'profile.display_name']);

        return [
            'name' => $user->profile?->display_name ?: $user->account_id, 'accountId' => $user->account_id,
            'remark' => $user->support_remark, 'remarkRevision' => (int) $user->support_remark_revision,
            'email' => $user->email, 'registeredAt' => $user->created_at->toIso8601String(),
            'rank' => app(ManualPromotion::class)->benefit($tenant, $user->id, CarbonImmutable::now())?->rank ?? 0,
            'partner' => DB::table('partner_configurations')->where('tenant_id', $tenant)->where('user_id', $user->id)->where('enabled', true)->exists(),
            'balances' => DB::table('ledger_accounts')->where('tenant_id', $tenant)->where('user_id', $user->id)->where('account_type', 'USER_AVAILABLE')->groupBy('asset_code')->orderBy('asset_code')->selectRaw('asset_code AS asset, SUM(balance)::text AS amount')->get(),
            'deposits' => $this->totals($tenant, $user->id, false),
            'withdrawals' => $this->totals($tenant, $user->id, true),
            'referrer' => $parent ? ['name' => $parent->display_name ?: $parent->account_id, 'accountId' => $parent->account_id, 'email' => $parent->email] : null,
        ];
    }

    private function totals(string $tenant, string $user, bool $withdrawal)
    {
        $primary = DB::table($withdrawal ? 'withdrawal_orders' : 'wallet_topup_orders')
            ->where('tenant_id', $tenant)->where('user_id', $user)
            ->where('status', $withdrawal ? 'SUCCEEDED' : 'CREDITED')
            ->whereNotNull($withdrawal ? 'settlement_ledger_entry_id' : 'ledger_entry_id')->selectRaw('asset_code, '.($withdrawal ? 'amount' : 'COALESCE(actual_received_amount, amount)').' AS amount');
        $asset = DB::table($withdrawal ? 'asset_withdrawal_orders' : 'asset_deposit_orders')
            ->where('tenant_id', $tenant)->where('user_id', $user)
            ->where('status', $withdrawal ? 'COMPLETED' : 'CREDITED')
            ->whereNotNull('ledger_entry_id')->selectRaw('asset_code, '.($withdrawal ? 'amount' : 'COALESCE(actual_received_amount, amount)').' AS amount');

        return DB::query()->fromSub($primary->unionAll($asset), 'orders')->groupBy('asset_code')->orderBy('asset_code')
            ->selectRaw('asset_code AS asset, SUM(amount)::text AS amount')->get();
    }
}
