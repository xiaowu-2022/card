<?php

namespace App\Application\Partners;

use App\Application\Promotion\PaidPromotionQuery;
use App\Application\Promotion\PromotionRanks;
use App\Application\Promotion\PromotionReportQuery;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class PartnerInvitationReport
{
    public function read(string $partner, ?string $company, ?string $kind, ?int $rank, int $page): array
    {
        $outer = DB::transactionLevel();

        return DB::transaction(function () use ($partner, $company, $kind, $rank, $page, $outer) {
            if ($outer === 0) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $record = DB::table('partner_configurations')->where('id', $partner)
                ->when($company, fn ($q) => $q->where('tenant_id', $company))->first();
            abort_unless($record, 404);
            $tenant = Tenant::findOrFail($record->tenant_id);
            $user = User::where('tenant_id', $tenant->id)->findOrFail($record->user_id);
            abort_if($rank !== null && ! in_array($rank, PromotionRanks::forTenant($tenant->id), true), 422);
            $query = app(PaidPromotionQuery::class);
            // Use the consumer's projection, never PromotionQuery::execute (which ensures membership).
            $paid = $query->execute($tenant->id, $user->id);
            $details = $kind === null ? null : $query->details($tenant->id, $user->id, $kind, $rank, $page);
            if ($details !== null) {
                $emails = User::where('tenant_id', $tenant->id)->whereIn('account_id', array_column($details['items'], 'accountId'))->pluck('email', 'account_id');
                foreach ($details['items'] as &$item) {
                    $item['email'] = $emails[$item['accountId']] ?? null;
                    $item['occurredAt'] = CarbonImmutable::parse($item['occurredAt'])->toIso8601String();
                }
                unset($item);
            }

            return [
                'account' => ['partnerId' => $record->id, 'companyId' => $tenant->id, 'companyName' => $tenant->name, 'accountId' => $user->account_id, 'email' => $user->email],
                'timezone' => $tenant->timezone,
                'commission' => app(PromotionReportQuery::class)->cumulative($tenant->id, $user->id),
                'summary' => Arr::only($paid, ['tables', 'totals', 'legacy', 'teamByLevel', 'directPeople', 'indirectPeople']),
                'details' => $details,
            ];
        });
    }
}
