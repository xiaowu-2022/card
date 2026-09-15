<?php

namespace App\Application\Promotion;

use App\Domain\Promotion\Models\PromotionLevel;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class AdminPromotionQuery
{
    public function __construct(private PromotionMembershipAction $members) {}

    public function execute(string $tenantId, ?string $accountId, int $page): array
    {
        $users = DB::table('users as u')->leftJoin('promotion_members as m', fn ($join) => $join->on('m.user_id', '=', 'u.id')->on('m.tenant_id', '=', 'u.tenant_id'))
            ->where('u.tenant_id', $tenantId)->when($accountId, fn ($q) => $q->where('u.account_id', $accountId))->orderBy('u.account_id')
            ->offset(($page - 1) * 30)->limit(31)->get(['u.account_id', 'm.level_id', 'm.invitation_code']);

        return ['companyCode' => $this->members->companyInvitation($tenantId)->invitation_code,
            'levels' => PromotionLevel::query()->where('tenant_id', $tenantId)->orderBy('rank')->get()->map(fn ($row) => ['id' => $row->id, 'rank' => $row->rank, 'name' => $row->name, 'reward' => $row->reward_amount, 'revision' => $row->revision])->all(),
            'members' => $users->take(30)->map(fn ($row) => ['accountId' => $row->account_id, 'levelId' => $row->level_id, 'code' => $row->invitation_code])->all(),
            'accountId' => $accountId ?? '', 'page' => $page, 'hasMore' => $users->count() > 30];
    }

    public function userId(string $tenantId, string $accountId): string
    {
        return User::query()->where('tenant_id', $tenantId)->where('account_id', $accountId)->firstOrFail()->id;
    }
}
