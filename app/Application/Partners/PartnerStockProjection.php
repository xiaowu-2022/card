<?php

namespace App\Application\Partners;

use App\Application\User\PlatformUserFinancialSummary;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Request-local, read-only bottom-up projection. Never retain scoped balances between reads. */
final class PartnerStockProjection
{
    public function forUsers(string $tenant, array $roots): array
    {
        if ($roots === []) {
            return [];
        }
        $partners = DB::table('partner_configurations')->where('tenant_id', $tenant)->where('enabled', true)->get(['id', 'user_id'])->keyBy('user_id');
        $members = DB::table('promotion_members')->where('tenant_id', $tenant)->get(['id', 'user_id', 'inviter_id']);
        $byUser = $members->keyBy('user_id');
        $branches = $members->groupBy('inviter_id');
        $children = $needed = [];
        $discover = function (string $user) use (&$discover, &$children, &$needed, $partners, $byUser, $branches): void {
            if (isset($needed[$user]) || ! isset($partners[$user])) {
                return;
            }
            $needed[$user] = $partners[$user];
            $children[$user] = [];
            $queue = isset($byUser[$user]) ? [$byUser[$user]->id] : [];
            $seen = [];
            while ($queue !== []) {
                $id = array_pop($queue);
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                foreach ($branches->get($id, collect()) as $member) {
                    if (isset($partners[$member->user_id])) {
                        $children[$user][$member->user_id] = $member->user_id;
                    } else {
                        $queue[] = $member->id;
                    }
                }
            }
            foreach ($children[$user] as $child) {
                $discover($child);
            }
        };
        foreach (array_unique($roots) as $root) {
            $discover($root);
        }
        $result = [];
        // Bound SQL UNION size, aggregate evidence and personal balances in batches.
        foreach (array_chunk($needed, 50, true) as $batch) {
            $users = array_keys($batch);
            $income = app(PlatformUserFinancialSummary::class)->forUsers(array_map(fn ($u) => (object) ['id' => $u, 'tenant_id' => $tenant], $users), ['commission' => true]);
            $advances = app(UserAdvances::class)->ownTotals($tenant, $users);
            $subordinate = app(UserAdvances::class)->subordinateTotals($tenant, $users);
            $subsidies = app(PartnerAnnualSubsidies::class)->totals($tenant, array_map(fn ($p) => $p->id, $batch));
            $journals = DB::table('partner_journal_entries')->where('tenant_id', $tenant)->whereIn('partner_id', array_map(fn ($p) => $p->id, $batch))
                ->select('partner_id')->selectRaw("COALESCE(SUM(CASE WHEN reverses_id IS NULL THEN amount ELSE -amount END) FILTER(WHERE kind='REIMBURSEMENT'),0)::text AS reimbursement,
                    COALESCE(SUM((CASE WHEN reverses_id IS NULL THEN amount ELSE -amount END)*(CASE WHEN kind='ADJUSTMENT_DECREASE' THEN -1 ELSE 1 END)) FILTER(WHERE kind IN ('ADJUSTMENT_INCREASE','ADJUSTMENT_DECREASE')),0)::text AS adjustment,
                    COUNT(*) FILTER(WHERE kind IN ('ADJUSTMENT_INCREASE','ADJUSTMENT_DECREASE')) AS adjustment_count")
                ->groupBy('partner_id')->get()->keyBy('partner_id');
            $actual = DB::table('ledger_accounts')->where('tenant_id', $tenant)->whereIn('user_id', $users)->where('asset_code', 'USDT')->where('account_type', 'USER_AVAILABLE')
                ->select('user_id')->selectRaw('SUM(balance)::text AS amount')->groupBy('user_id')->pluck('amount', 'user_id');
            $entries = null;
            foreach ($users as $user) {
                $query = DB::query()->fromSub(app(PartnerBusinessStock::class)->entries($tenant, $user), 'e')->select('category', 'amount')->selectRaw('?::uuid AS root', [$user]);
                $entries === null ? $entries = $query : $entries->unionAll($query);
            }
            $sums = DB::query()->fromSub($entries, 'e')->select('root', 'category')->selectRaw('SUM(amount)::text AS amount')->groupBy('root', 'category')->get()->groupBy('root');
            foreach ($batch as $user => $partner) {
                $totals = array_fill_keys(['deposits', 'annual', 'activation', 'annualCommission', 'otherCommission', 'rebates', 'reimbursements'], '0.00000000');
                foreach ($sums->get($user, collect()) as $sum) {
                    $totals[$sum->category] = (string) BigDecimal::of($sum->amount)->toScale(8);
                }
                $totals['inflow'] = (string) BigDecimal::of($totals['deposits'])->plus($totals['annual']);
                $totals['outflow'] = (string) BigDecimal::of($totals['activation'])->plus($totals['annualCommission'])->plus($totals['otherCommission'])->plus($totals['rebates'])->plus($totals['reimbursements']);
                $j = $journals->get($partner->id);
                $f = $income[$tenant.':'.$user];
                $balance = PartnerAccountBalance::calculate($advances[$user] ?? '0', $f['annualExpense'], $f['activationCommission'], $f['annualCommission'], $f['unclassifiedCommission'], $j->reimbursement ?? '0', $j->adjustment ?? '0', $actual[$user] ?? '0', ($j->adjustment_count ?? 0) > 0);
                $result[$user] = ['partnerId' => $partner->id, 'totals' => $totals, 'accountBalance' => $balance, 'annualSubsidy' => (string) BigDecimal::of($subsidies[$partner->id] ?? '0')->toScale(8), 'subordinateAdvances' => (string) BigDecimal::of($subordinate[$user] ?? '0')->toScale(8), 'children' => array_values($children[$user])];
            }
        }
        $visiting = [];
        $calculate = function (string $user) use (&$calculate, &$result, &$visiting): void {
            if (isset($result[$user]['stock'])) {
                return;
            }
            if (isset($visiting[$user])) {
                throw new RuntimeException('Cyclic partner stock hierarchy.');
            }
            $visiting[$user] = true;
            $childStock = BigDecimal::zero();
            foreach ($result[$user]['children'] as $child) {
                $calculate($child);
                $childStock = $childStock->plus($result[$child]['stockForParent']);
            }
            $r = &$result[$user];
            $r['childrenStock'] = (string) $childStock->toScale(8);
            $r['stockBeforeDifference'] = (string) BigDecimal::of($r['totals']['inflow'])->minus($r['totals']['outflow'])->plus($childStock)->toScale(8);
            $r['stock'] = PartnerStockValue::afterDifference($r['stockBeforeDifference'], $r['accountBalance']['difference'], $r['subordinateAdvances'], $r['annualSubsidy']);
            $r['stockForParent'] = (string) BigDecimal::of($r['stock'])->plus($r['annualSubsidy'])->toScale(8);
            unset($visiting[$user]);
        };
        foreach (array_keys($result) as $user) {
            $calculate($user);
        }

        return $result;
    }

    public function details(string $tenant, string $user, array $projection, int $page): array
    {
        $ids = $projection[$user]['children'];
        $rows = DB::table('users')->where('tenant_id', $tenant)->whereIn('id', $ids)->orderBy('account_id')->orderBy('id')->paginate(20, ['id', 'account_id'], 'flow_page', $page);

        return ['direction' => 'childrenStock', 'page' => $rows->currentPage(), 'total' => $rows->total(), 'hasMore' => $rows->hasMorePages(), 'items' => array_map(function ($u) use ($projection) {
            $r = $projection[$u->id];

            return ['id' => $r['partnerId'], 'source' => 'childrenStock', 'account_id' => $u->account_id, 'email' => null, 'direct_account_id' => null, 'direct_email' => null, 'asset_code' => 'USDT', 'amount' => $r['stockForParent'], 'amountUsdt' => $r['stockForParent'], 'stock' => $r['stock'], 'annualSubsidy' => $r['annualSubsidy'], 'posted_at' => null];
        }, $rows->items())];
    }
}
