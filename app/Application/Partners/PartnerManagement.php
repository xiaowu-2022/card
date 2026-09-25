<?php

namespace App\Application\Partners;

use App\Application\Assets\AssetAccess;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\User\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class PartnerManagement
{
    public function configure(AdminUser $actor, string $tenant, array $input): object
    {
        app(AssetAccess::class)->platform($actor, 'partners.manage');
        $data = Validator::make($input, ['account_id' => 'required|string|max:40', 'enabled' => 'required|boolean', 'share_percent' => ['required', 'regex:/^\d{1,3}(\.\d{1,8})?$/', 'numeric', 'between:0,100']])->validate();

        return DB::transaction(function () use ($actor, $tenant, $data) {
            app(AssetAccess::class)->platform($actor, 'partners.manage');
            $user = User::where('tenant_id', $tenant)->where('account_id', $data['account_id'])->lockForUpdate()->firstOrFail();
            $old = DB::table('partner_configurations')->where('tenant_id', $tenant)->where('user_id', $user->id)->first();
            $id = $old?->id ?? (string) Str::uuid();
            DB::table('partner_configurations')->updateOrInsert(['id' => $id], ['tenant_id' => $tenant, 'user_id' => $user->id, 'enabled' => $data['enabled'], 'share_percent' => $data['share_percent'], 'updated_by' => $actor->id, 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);
            app(AuditLogger::class)->record($tenant, 'ADMIN', $actor->id, 'PARTNER_CONFIGURED', 'partner', $id, $old ? (array) $old : null, $data);

            return DB::table('partner_configurations')->where('id', $id)->first();
        });
    }

    public function journal(AdminUser $actor, string $tenant, string $partner, array $input): object
    {
        app(AssetAccess::class)->platform($actor, 'partners.manage');
        $data = Validator::make($input, ['kind' => 'required|in:REIMBURSEMENT,ADVANCE', 'amount' => ['required', 'regex:/^\d{1,18}(\.\d{1,8})?$/', 'numeric', 'gt:0'], 'business_date' => 'required|date_format:Y-m-d', 'note' => 'required|string|max:2000', 'request_id' => 'required|uuid', 'reverses_id' => 'nullable|uuid'])->validate();
        $data['amount'] = (string) BigDecimal::of($data['amount'])->toScale(8);
        $data['reverses_id'] ??= null;
        $hash = hash('sha256', json_encode([$partner, $data]));

        return DB::transaction(function () use ($actor, $tenant, $partner, $data, $hash) {
            app(AssetAccess::class)->platform($actor, 'partners.manage');
            DB::table('partner_configurations')->where('tenant_id', $tenant)->where('id', $partner)->lockForUpdate()->firstOrFail();
            AssetAccess::lock('partner-journal:'.$tenant.':'.$data['request_id']);
            $old = DB::table('partner_journal_entries')->where('tenant_id', $tenant)->where('request_id', $data['request_id'])->first();
            if ($old) {
                abort_unless($old->request_hash === $hash, 409);

                return $old;
            }
            if ($data['reverses_id']) {
                $original = DB::table('partner_journal_entries')->where('tenant_id', $tenant)->where('partner_id', $partner)->where('id', $data['reverses_id'])->firstOrFail();
                abort_unless(! $original->reverses_id && $original->kind === $data['kind'] && BigDecimal::of($original->amount)->isEqualTo($data['amount']), 422);
                abort_if(DB::table('partner_journal_entries')->where('reverses_id', $original->id)->exists(), 409);
            }
            $id = (string) Str::uuid();
            DB::table('partner_journal_entries')->insert($data + ['id' => $id, 'tenant_id' => $tenant, 'partner_id' => $partner, 'actor_id' => $actor->id, 'request_hash' => $hash, 'created_at' => now()]);
            app(AuditLogger::class)->record($tenant, 'ADMIN', $actor->id, 'PARTNER_JOURNAL_RECORDED', 'partner_journal', $id, null, $data, $data['request_id']);

            return DB::table('partner_journal_entries')->where('id', $id)->first();
        });
    }
}
