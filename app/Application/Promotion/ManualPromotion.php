<?php

namespace App\Application\Promotion;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ManualPromotion
{
    public function latest(string $tenant, string $user, ?CarbonImmutable $at = null): ?object
    {
        return DB::table('manual_promotion_adjustments')->where('tenant_id', $tenant)->where('user_id', $user)
            ->where('created_at', '<=', $at ?? CarbonImmutable::now())->orderByDesc('created_at')->orderByDesc('id')->first();
    }

    public function managed(string $tenant, string $user): bool
    {
        return $this->latest($tenant, $user)?->rank !== null;
    }

    /** One projection for all current qualification/rank readers. Paid accounting still uses real cycles. */
    public function query(string $tenant, ?CarbonImmutable $at = null): Builder
    {
        $at ??= CarbonImmutable::now();
        $manual = DB::table('manual_promotion_adjustments')->where('tenant_id', $tenant)->where('created_at', '<=', $at)
            ->selectRaw('DISTINCT ON (user_id) *')->orderBy('user_id')->orderByDesc('created_at')->orderByDesc('id');
        $paid = DB::table('paid_promotion_cycles')->where('tenant_id', $tenant)->where('starts_at', '<=', $at)->where('ends_at', '>', $at)
            ->selectRaw('DISTINCT ON (user_id) *')->orderBy('user_id')->orderByDesc('starts_at');

        return DB::table('users as effective_user')->where('effective_user.tenant_id', $tenant)
            ->leftJoinSub($manual, 'manual', 'manual.user_id', '=', 'effective_user.id')
            ->leftJoinSub($paid, 'paid', 'paid.user_id', '=', 'effective_user.id')
            ->selectRaw('effective_user.tenant_id,effective_user.id AS user_id,
                CASE WHEN manual.rank IS NOT NULL THEN NULL::uuid ELSE paid.id END AS id,
                CASE WHEN manual.rank IS NOT NULL THEN manual.id ELSE NULL END AS manual_id,
                COALESCE(manual.rank,paid.rank,0) AS rank,
                CASE WHEN manual.rank IS NOT NULL THEN manual.percent ELSE COALESCE(paid.percent,0) END AS percent,
                CASE WHEN manual.rank IS NOT NULL THEN manual.reward ELSE COALESCE(paid.reward,20) END AS reward,
                CASE WHEN manual.rank IS NOT NULL THEN manual.revision ELSE COALESCE(paid.revision,1) END AS revision,
                CASE WHEN manual.rank IS NOT NULL THEN manual.created_at ELSE paid.starts_at END AS starts_at,
                CASE WHEN manual.rank IS NOT NULL THEN NULL ELSE paid.ends_at END AS ends_at');
    }

    public function benefit(string $tenant, string $user, ?CarbonImmutable $at = null): ?object
    {
        $value = $this->query($tenant, $at)->where('effective_user.id', $user)->first();

        return $value && $value->rank > 0 ? $value : null;
    }

    public function adjust(string $tenant, string $user, AdminUser $actor, string $choice, string $reason, string $requestId, ?string $expected): object
    {
        $rules = app(PaidPromotionRules::class);
        $rules->platform($actor, 'promotion_members.manage');
        $rules->requestId($requestId);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new DomainException('PROMOTION_REASON_REQUIRED', 'Enter an adjustment reason, up to 500 characters.');
        }

        return DB::transaction(function () use ($tenant, $user, $actor, $choice, $reason, $requestId, $expected, $rules) {
            Tenant::query()->whereKey($tenant)->lockForUpdate()->firstOrFail();
            User::query()->where('tenant_id', $tenant)->whereKey($user)->lockForUpdate()->firstOrFail();
            $rules->platform($actor, 'promotion_members.manage');
            $existing = DB::table('manual_promotion_adjustments')->where('tenant_id', $tenant)->where('user_id', $user)->where('request_id', $requestId)->first();
            if ($existing) {
                if ($existing->choice !== $choice || $existing->reason !== $reason || $existing->actor_id !== $actor->id || $existing->expected_adjustment_id !== $expected) {
                    throw new DomainException('PROMOTION_REQUEST_CONFLICT', 'This request was already used with different details.', 409);
                }

                return $existing;
            }
            $previous = $this->latest($tenant, $user);
            if ($previous?->id !== $expected) {
                throw new DomainException('PROMOTION_ADJUSTMENT_CHANGED', 'The promotion level changed. Refresh before adjusting it.', 409);
            }
            $level = null;
            if (! in_array($choice, ['ordinary', 'paid'], true)) {
                if (! Str::isUuid($choice)) {
                    abort(422);
                }
                $level = DB::table('paid_promotion_levels')->where('tenant_id', $tenant)->where('id', $choice)->firstOrFail();
            }
            $before = $this->benefit($tenant, $user)?->rank ?? 0;
            $paid = $rules->cycle($tenant, $user);
            $rank = $choice === 'paid' ? null : ($level?->rank ?? 0);
            $id = (string) Str::uuid7();
            $values = ['id' => $id, 'tenant_id' => $tenant, 'user_id' => $user, 'actor_id' => $actor->id, 'actor_name' => $actor->fresh()->name,
                'request_id' => $requestId, 'expected_adjustment_id' => $expected, 'choice' => $choice, 'level_id' => $level?->id,
                'rank' => $rank, 'previous_rank' => $before, 'effective_rank' => $rank ?? ($paid?->rank ?? 0),
                'revision' => $level?->revision ?? 1, 'percent' => $level?->percent ?? 0, 'reward' => $level?->reward ?? 20,
                'reason' => $reason, 'created_at' => CarbonImmutable::now()->format('Y-m-d H:i:sP')];
            DB::table('manual_promotion_adjustments')->insert($values);
            app(AuditLogger::class)->record($tenant, 'ADMIN', $actor->id, 'PROMOTION_LEVEL_ADJUSTED', 'user', $user,
                ['rank' => $before, 'adjustment_id' => $previous?->id], ['rank' => $values['effective_rank'], 'adjustment_id' => $id, 'reason' => $reason, 'mode' => $choice === 'paid' ? 'PAID' : 'MANUAL'], $requestId);

            return DB::table('manual_promotion_adjustments')->where('id', $id)->first();
        }, 3);
    }

    public function assertPurchasable(string $tenant, string $user): void
    {
        if ($this->managed($tenant, $user)) {
            throw new DomainException('PROMOTION_MANUALLY_MANAGED', 'Your promotion level is managed by the platform. No annual payment is needed. Contact support to change it.', 409);
        }
    }
}
