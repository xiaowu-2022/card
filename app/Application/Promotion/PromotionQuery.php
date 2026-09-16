<?php

namespace App\Application\Promotion;

use App\Domain\Kyc\Enums\KycUserStatus;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Promotion\Models\CommissionAward;
use App\Domain\Promotion\Models\PromotionFundingEvent;
use App\Domain\Promotion\Models\PromotionLevel;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Wallet\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class PromotionQuery
{
    public function __construct(private PromotionMembershipAction $members, private KycStatusService $kyc) {}

    public function execute(string $tenantId, string $userId, ?string $date, int $page = 1, int $directPage = 1, ?string $accountId = null, string $funding = 'all'): array
    {
        $tenant = Tenant::query()->whereKey($tenantId)->firstOrFail();
        $member = $this->members->ensure($tenantId, $userId);
        $day = $date ? CarbonImmutable::createFromFormat('!Y-m-d', $date, $tenant->timezone) : CarbonImmutable::now($tenant->timezone)->startOfDay();
        $start = $day->utc();
        $end = $day->addDay()->utc();
        $team = DB::select(<<<'SQL'
            WITH RECURSIVE team AS (
                SELECT id, user_id, inviter_id, created_at FROM promotion_members WHERE tenant_id=? AND inviter_id=?
                UNION ALL
                SELECT m.id, m.user_id, m.inviter_id, m.created_at FROM promotion_members m JOIN team t ON m.inviter_id=t.id WHERE m.tenant_id=?
            ) SELECT * FROM team
            SQL, [$tenantId, $member->id, $tenantId]);
        $ids = array_column($team, 'user_id');
        $beneficiaries = [$userId, ...$ids];
        $funds = PromotionFundingEvent::query()->where('tenant_id', $tenantId)->whereIn('user_id', $ids);
        $awards = CommissionAward::query()->join('ledger_entries as earned_entry', function ($join): void {
            $join->on('earned_entry.id', '=', 'commission_awards.ledger_entry_id')->on('earned_entry.tenant_id', '=', 'commission_awards.tenant_id');
        })->where('commission_awards.tenant_id', $tenantId)->whereIn('commission_awards.user_id', $beneficiaries);
        $firstFunding = (clone $funds)->selectRaw('user_id, MIN(funded_at) AS activated_at')->groupBy('user_id')->get();
        $inDay = fn ($time) => CarbonImmutable::parse($time)->greaterThanOrEqualTo($start) && CarbonImmutable::parse($time)->lessThan($end);
        $level = PromotionLevel::query()->where('tenant_id', $tenantId)->whereKey($member->level_id)->first();
        $directQuery = DB::table('promotion_members as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.tenant_id', $tenantId)->where('u.tenant_id', $tenantId)->where('m.inviter_id', $member->id)
            ->when($accountId, fn ($q) => $q->where('u.account_id', 'like', '%'.$accountId.'%'));
        $depositExists = fn ($q) => $q->selectRaw('1')->from('ledger_accounts as d')->whereColumn('d.user_id', 'm.user_id')
            ->where('d.tenant_id', $tenantId)->where('d.account_type', 'USER_SECURITY_DEPOSIT')->where('d.balance', '>', 0);
        if ($funding === 'funded') {
            $directQuery->whereExists($depositExists);
        }
        if ($funding === 'unfunded') {
            $directQuery->whereNotExists($depositExists);
        }
        $directTotal = (clone $directQuery)->count();
        $direct = $directQuery->orderBy('m.created_at', 'desc')->orderBy('m.id')->offset(($directPage - 1) * 20)->limit(21)
            ->get(['m.id', 'm.user_id', 'u.account_id', 'm.level_id', 'm.created_at']);
        $directBalances = DB::table('ledger_accounts')->where('tenant_id', $tenantId)->whereIn('user_id', $direct->pluck('user_id'))
            ->where('account_type', 'USER_SECURITY_DEPOSIT')->where('asset_code', 'USDT')->pluck('balance', 'user_id')->map(fn ($amount) => Money::of($amount, 'USDT')->amount());
        $directContributions = DB::table('commission_awards as a')->join('promotion_funding_events as f', function ($join): void {
            $join->on('f.id', '=', 'a.funding_event_id')->on('f.tenant_id', '=', 'a.tenant_id');
        })->where('a.tenant_id', $tenantId)->where('a.user_id', $userId)->whereIn('f.user_id', $direct->pluck('user_id'))
            ->selectRaw('f.user_id, SUM(a.amount)::text AS amount')->groupBy('f.user_id')->pluck('amount', 'f.user_id');
        $details = DB::table('promotion_members as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.tenant_id', $tenantId)->where('u.tenant_id', $tenantId)->whereIn('m.user_id', $ids)
            ->where('m.created_at', '>=', $start)->where('m.created_at', '<', $end)
            ->selectRaw("m.id, u.account_id, 'Invitation' AS kind, NULL::text AS amount, m.created_at AS occurred_at, m.user_id AS source_user_id, NULL::text AS deposit_amount");
        $fundDetails = DB::table('promotion_funding_events as f')->join('users as u', 'u.id', '=', 'f.user_id')
            ->where('f.tenant_id', $tenantId)->where('u.tenant_id', $tenantId)->whereIn('f.user_id', $ids)->where('f.funded_at', '>=', $start)->where('f.funded_at', '<', $end)
            ->selectRaw("f.id, u.account_id, 'Deposit funded' AS kind, f.amount::text AS amount, f.funded_at AS occurred_at, f.user_id AS source_user_id, f.amount::text AS deposit_amount");
        $awardDetails = DB::table('commission_awards as a')->join('users as u', 'u.id', '=', 'a.user_id')
            ->join('promotion_funding_events as source_fund', function ($join): void {
                $join->on('source_fund.id', '=', 'a.funding_event_id')->on('source_fund.tenant_id', '=', 'a.tenant_id');
            })
            ->join('ledger_entries as e', function ($join): void {
                $join->on('e.id', '=', 'a.ledger_entry_id')->on('e.tenant_id', '=', 'a.tenant_id');
            })
            ->where('a.tenant_id', $tenantId)->where('u.tenant_id', $tenantId)->where('a.user_id', $userId)->where('e.posted_at', '>=', $start)->where('e.posted_at', '<', $end)
            ->selectRaw("a.id, u.account_id, 'Commission earned' AS kind, a.amount::text AS amount, e.posted_at AS occurred_at, source_fund.user_id AS source_user_id, source_fund.amount::text AS deposit_amount");
        $activationDetails = DB::query()->fromSub((clone $funds)->selectRaw('user_id, MIN(funded_at) AS activated_at')->groupBy('user_id'), 'f')
            ->join('users as u', 'u.id', '=', 'f.user_id')->where('u.tenant_id', $tenantId)->where('f.activated_at', '>=', $start)->where('f.activated_at', '<', $end)
            ->selectRaw("u.id, u.account_id, 'Activation' AS kind, NULL::text AS amount, f.activated_at AS occurred_at, f.user_id AS source_user_id, NULL::text AS deposit_amount");
        $rows = DB::query()->fromSub($details->unionAll($fundDetails)->unionAll($awardDetails)->unionAll($activationDetails), 'movements')
            ->orderByDesc('occurred_at')->orderBy('id')->orderBy('kind')->offset(($page - 1) * 30)->limit(31)->get();

        $relationships = DB::table('promotion_members as source_member')
            ->join('users as source_user', function ($join): void {
                $join->on('source_user.id', '=', 'source_member.user_id')->on('source_user.tenant_id', '=', 'source_member.tenant_id');
            })->join('promotion_members as inviter', function ($join): void {
                $join->on('inviter.id', '=', 'source_member.inviter_id')->on('inviter.tenant_id', '=', 'source_member.tenant_id');
            })->join('users as inviter_user', function ($join): void {
                $join->on('inviter_user.id', '=', 'inviter.user_id')->on('inviter_user.tenant_id', '=', 'inviter.tenant_id');
            })->where('source_member.tenant_id', $tenantId)->whereIn('source_member.user_id', $rows->take(30)->pluck('source_user_id'))
            ->get(['source_member.user_id', 'source_user.account_id', 'inviter.user_id as inviter_user_id', 'inviter_user.account_id as inviter_account_id'])->keyBy('user_id');

        $refundRestricted = CommissionTransferEligibility::refundRestricted($tenantId, $userId);

        return [
            'date' => $day->format('Y-m-d'), 'timezone' => $tenant->timezone, 'invitationCode' => $member->invitation_code,
            'levelName' => $level?->name, 'supported' => $tenant->default_asset === 'USDT',
            'commissionRefundRestricted' => $refundRestricted,
            'canTransfer' => ! $refundRestricted && Wallet::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('asset_code', 'USDT')->where('status', 'ACTIVE')->exists()
                && $this->kyc->forUser($tenantId, $userId) === KycUserStatus::Approved,
            'availableCommission' => LedgerAccount::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('account_type', 'USER_COMMISSION')->value('balance') ?? '0.00000000',
            'myCommission' => (string) CommissionAward::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->sum('amount'),
            'totals' => ['invited' => count($ids), 'activated' => $firstFunding->count(), 'deposits' => (string) (clone $funds)->sum('amount'), 'commission' => (string) (clone $awards)->sum('amount')],
            'daily' => ['invited' => count(array_filter($team, fn ($row) => $inDay($row->created_at))), 'activated' => $firstFunding->filter(fn ($row) => $inDay($row->activated_at))->count(),
                'deposits' => (string) (clone $funds)->where('funded_at', '>=', $start)->where('funded_at', '<', $end)->sum('amount'),
                'commission' => (string) (clone $awards)->where('commission_awards.user_id', $userId)->where('earned_entry.posted_at', '>=', $start)->where('earned_entry.posted_at', '<', $end)->sum('commission_awards.amount')],
            'direct' => $direct->take(20)->map(fn ($row) => ['id' => $row->id, 'accountId' => $row->account_id, 'levelId' => $row->level_id, 'joinedAt' => $row->created_at, 'depositAmount' => $directBalances[$row->user_id] ?? '0.00000000', 'myCommission' => $directContributions[$row->user_id] ?? '0.00000000'])->all(),
            'directTotal' => $directTotal, 'filters' => ['accountId' => $accountId ?? '', 'funding' => $funding],
            'directPage' => $directPage, 'hasMoreDirect' => $direct->count() > 20,
            'assignableLevels' => PromotionLevel::query()->where('tenant_id', $tenantId)->where('rank', '<', $level?->rank ?? 0)->orderBy('rank')->get()->map(fn ($row) => ['id' => $row->id, 'name' => $row->name])->all(),
            'canAssign' => ($level?->rank ?? 0) > 0,
            'details' => $rows->take(30)->map(function ($row) use ($relationships, $userId): array {
                $relationship = $relationships->get($row->source_user_id);

                return ['id' => $row->id.':'.$row->kind, 'accountId' => $row->account_id, 'kind' => $row->kind, 'amount' => $row->amount, 'occurredAt' => $row->occurred_at,
                    'sourceAccountId' => $relationship?->account_id, 'inviterAccountId' => $relationship?->inviter_account_id,
                    'invitedByMe' => $relationship?->inviter_user_id === $userId,
                    'depositAmount' => $row->deposit_amount];
            })->all(),
            'page' => $page, 'hasMore' => $rows->count() > 30,
        ];
    }
}
