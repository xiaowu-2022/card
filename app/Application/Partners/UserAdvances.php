<?php

namespace App\Application\Partners;

use App\Application\Assets\AssetAccess;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class UserAdvances
{
    public function record(AdminUser $actor, string $tenant, string $user, array $input, bool $receiptCorrection = false): object
    {
        $access = app(AssetAccess::class);
        $access->platform($actor, 'users.read');
        $access->platform($actor, 'partners.manage');
        $data = Validator::make($input, [
            'amount' => ['required', 'string', 'regex:/^-?\d{1,18}(\.\d{1,8})?$/'],
            'business_date' => 'required|date_format:Y-m-d', 'note' => 'required|string|max:2000',
            'request_id' => 'required|uuid', 'reverses_id' => 'nullable|uuid', 'confirmed' => 'required|accepted',
        ])->validate();
        $amount = BigDecimal::of($data['amount']);
        if ($amount->isZero()) {
            throw ValidationException::withMessages(['amount' => 'Amount must not be zero.']);
        }
        unset($data['confirmed']);
        $data['amount'] = (string) $amount->toScale(8);
        $data['reverses_id'] ??= null;
        $hash = hash('sha256', json_encode([$tenant, $user, $actor->id, $data]));

        return DB::transaction(function () use ($access, $actor, $tenant, $user, $data, $hash, $receiptCorrection) {
            $access->settlementOwners($tenant, $user);
            $access->platform($actor, 'users.read');
            $access->platform($actor, 'partners.manage');
            AssetAccess::lock('user-advance:'.$tenant.':'.$data['request_id']);
            $old = DB::table('user_advance_entries')->where('tenant_id', $tenant)->where('request_id', $data['request_id'])->first();
            if ($old) {
                abort_unless($old->request_hash === $hash, 409);

                return $old;
            }
            if ($data['reverses_id']) {
                $original = DB::table('user_advance_entries')->where('tenant_id', $tenant)->where('user_id', $user)->where('id', $data['reverses_id'])->firstOrFail();
                abort_unless(! $original->reverses_id && BigDecimal::of($original->amount)->isEqualTo($data['amount']), 422);
                abort_if(DB::table('user_advance_entries')->where('reverses_id', $original->id)->exists(), 409);
                if (! $receiptCorrection) {
                    $bound = DB::table('wallet_topup_orders')->where('tenant_id', $tenant)->where('user_advance_journal_id', $original->id)->exists()
                        || DB::table('asset_deposit_orders')->where('tenant_id', $tenant)->where('user_advance_journal_id', $original->id)->exists()
                        || DB::table('deposit_receipt_type_changes')->where('tenant_id', $tenant)->where('after_user_journal_id', $original->id)->exists();
                    abort_if($bound, 409, 'Change the deposit receipt classification to reverse this advance.');
                }
            }
            $id = (string) Str::uuid();
            DB::table('user_advance_entries')->insert($data + ['id' => $id, 'tenant_id' => $tenant, 'user_id' => $user, 'actor_id' => $actor->id, 'request_hash' => $hash, 'created_at' => now()]);
            app(AuditLogger::class)->record($tenant, 'ADMIN', $actor->id, 'USER_ADVANCE_RECORDED', 'user_advance', $id, null, $data, $data['request_id']);

            return DB::table('user_advance_entries')->where('id', $id)->first();
        });
    }

    /** Both retained partner evidence and independent user evidence follow current identity. */
    public function evidence(string $tenant): Builder
    {
        $users = DB::table('user_advance_entries as j')->where('j.tenant_id', $tenant)
            ->select('j.id', 'j.user_id', 'j.business_date', 'j.created_at', 'j.reverses_id')
            ->selectRaw("CASE WHEN j.reverses_id IS NULL THEN j.amount ELSE -j.amount END AS amount, 'user_advance'::text AS source");
        $partners = DB::table('partner_journal_entries as j')->join('partner_configurations as p', fn ($q) => $q->on('p.id', 'j.partner_id')->on('p.tenant_id', 'j.tenant_id'))
            ->where('j.tenant_id', $tenant)->where('j.kind', 'ADVANCE')->select('j.id', 'p.user_id', 'j.business_date', 'j.created_at', 'j.reverses_id')
            ->selectRaw("CASE WHEN j.reverses_id IS NULL THEN j.amount ELSE -j.amount END AS amount, 'partner_advance'::text AS source");

        return DB::query()->fromSub($users->unionAll($partners), 'advance');
    }

    public function ownTotals(string $tenant, array $users): array
    {
        return $this->evidence($tenant)->whereIn('user_id', $users)->select('user_id')->selectRaw('SUM(amount)::text AS amount')->groupBy('user_id')->pluck('amount', 'user_id')->all();
    }

    /** Stop recursion at enabled partners; UNION terminates cycles without path expansion. */
    public function members(string $tenant, array $roots): Builder
    {
        $ids = DB::table('promotion_members')->where('tenant_id', $tenant)->whereIn('user_id', $roots)->select('id', 'user_id');

        return DB::query()->fromRaw('(WITH RECURSIVE roots AS ('.$ids->toSql().'), team AS (
            SELECT r.user_id AS root,m.id,m.user_id FROM roots r JOIN promotion_members m ON m.inviter_id=r.id AND m.tenant_id=?
            WHERE m.user_id<>r.user_id AND NOT EXISTS(SELECT 1 FROM partner_configurations p WHERE p.tenant_id=? AND p.user_id=m.user_id AND p.enabled)
            UNION SELECT t.root,m.id,m.user_id FROM team t JOIN promotion_members m ON m.inviter_id=t.id AND m.tenant_id=?
            WHERE m.user_id<>t.root AND NOT EXISTS(SELECT 1 FROM partner_configurations p WHERE p.tenant_id=? AND p.user_id=m.user_id AND p.enabled)
        ) SELECT DISTINCT root,user_id FROM team) AS advance_team', [...$ids->getBindings(), $tenant, $tenant, $tenant, $tenant]);
    }

    public function subordinateTotals(string $tenant, array $roots): array
    {
        return DB::query()->fromSub($this->members($tenant, $roots), 't')
            ->joinSub($this->evidence($tenant), 'a', 'a.user_id', '=', 't.user_id')->select('t.root')
            ->selectRaw('SUM(a.amount)::text AS amount')->groupBy('t.root')->pluck('amount', 'root')->all();
    }

    public function details(string $tenant, string $root, int $page): array
    {
        $rows = $this->evidence($tenant)->whereIn('advance.user_id', $this->members($tenant, [$root])->select('user_id'))
            ->join('users as u', fn ($j) => $j->on('u.id', 'advance.user_id')->where('u.tenant_id', $tenant))
            ->orderByDesc('advance.created_at')->orderBy('advance.id')->paginate(20, ['advance.*', 'u.account_id'], 'flow_page', $page);

        return ['items' => array_map(fn ($r) => ['id' => $r->id, 'source' => $r->source, 'account_id' => $r->account_id, 'email' => null, 'direct_account_id' => null, 'direct_email' => null, 'asset_code' => 'USDT', 'amount' => (string) BigDecimal::of($r->amount)->toScale(8), 'amountUsdt' => (string) BigDecimal::of($r->amount)->toScale(8), 'posted_at' => $r->created_at], $rows->items()), 'page' => $rows->currentPage(), 'pages' => $rows->lastPage(), 'total' => $rows->total(), 'hasMore' => $rows->hasMorePages(), 'direction' => 'subordinateAdvances'];
    }
}
