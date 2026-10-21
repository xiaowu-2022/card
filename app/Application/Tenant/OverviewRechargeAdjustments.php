<?php

namespace App\Application\Tenant;

use App\Application\Assets\AssetAccess;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Platform overview display only. Never updates receipts or posts Ledger. */
final class OverviewRechargeAdjustments
{
    public function record(AdminUser $actor, array $input): object
    {
        $access = app(AssetAccess::class);
        foreach (['tenant.read', 'wallet_topups.read', 'wallet_topups.confirm'] as $permission) {
            $access->platform($actor, $permission);
        }
        $data = Validator::make($input, [
            'amount' => ['required', 'string', 'regex:/^-?\d{1,18}(\.\d{1,8})?$/'],
            'business_date' => 'required|date_format:Y-m-d|before_or_equal:'.CarbonImmutable::now('Asia/Shanghai')->format('Y-m-d'),
            'reason' => 'required|string|max:2000', 'request_id' => 'required|uuid', 'confirmed' => 'required|accepted',
        ])->validate();
        $amount = BigDecimal::of($data['amount']);
        if ($amount->isZero()) {
            throw ValidationException::withMessages(['amount' => 'Amount must not be zero.']);
        }
        unset($data['confirmed']);
        $data['amount'] = (string) $amount->toScale(8);
        $hash = hash('sha256', json_encode([$actor->id, $data]));

        return DB::transaction(function () use ($actor, $access, $data, $hash) {
            AssetAccess::lock('overview-recharge-adjustments');
            foreach (['tenant.read', 'wallet_topups.read', 'wallet_topups.confirm'] as $permission) {
                $access->platform($actor, $permission);
            }
            $existing = DB::table('platform_overview_recharge_adjustments')->where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless($existing->request_hash === $hash, 409);

                return $existing;
            }
            $id = (string) Str::uuid();
            DB::table('platform_overview_recharge_adjustments')->insert($data + ['id' => $id, 'actor_id' => $actor->id, 'request_hash' => $hash, 'created_at' => CarbonImmutable::now()->toIso8601String()]);
            app(AuditLogger::class)->record(null, 'ADMIN', $actor->id, 'OVERVIEW_RECHARGE_DISPLAY_ADJUSTED', 'overview_recharge_adjustment', $id, null, $data, $data['request_id']);

            return DB::table('platform_overview_recharge_adjustments')->where('id', $id)->first();
        });
    }

    public function entries(CarbonImmutable $at): Builder
    {
        return DB::table('platform_overview_recharge_adjustments')->where('platform_overview_recharge_adjustments.created_at', '<=', $at->toIso8601String());
    }

    public function forPeriod(CarbonImmutable $at, string $period): Builder
    {
        $query = $this->entries($at);
        if ($period !== 'total') {
            $query->whereRaw('1=0');
        }

        return $query;
    }

    public function periods(CarbonImmutable $at): array
    {
        $total = (string) BigDecimal::of($this->entries($at)->sum('amount'))->toScale(8);

        return ['total' => $total] + array_fill_keys(['today', 'd3', 'd7', 'd15', 'd30'], '0.00000000');
    }

    public function history(CarbonImmutable $at, int $page, string $period = 'total'): array
    {
        $rows = $this->forPeriod($at, $period)->join('admin_users as actor', 'actor.id', '=', 'platform_overview_recharge_adjustments.actor_id')
            ->orderByDesc('platform_overview_recharge_adjustments.created_at')->orderBy('platform_overview_recharge_adjustments.id')
            ->paginate(20, ['platform_overview_recharge_adjustments.id', 'amount', 'business_date', 'reason', 'platform_overview_recharge_adjustments.created_at', 'actor.name as actor_name'], 'adjustment_page', $page);
        $rows->through(fn ($row) => ['id' => $row->id, 'amount' => (string) BigDecimal::of($row->amount)->toScale(8),
            'business_date' => $row->business_date, 'reason' => $row->reason, 'actor_name' => $row->actor_name,
            'created_at' => CarbonImmutable::parse($row->created_at)->toIso8601String()]);

        return ['amount' => $this->periods($at)[$period], 'items' => $rows->items(), 'page' => $rows->currentPage(), 'pages' => $rows->lastPage(), 'total' => $rows->total()];
    }
}
