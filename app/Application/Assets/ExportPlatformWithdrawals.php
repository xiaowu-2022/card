<?php

namespace App\Application\Assets;

use App\Application\Promotion\ManualPromotion;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Withdrawal\Services\WithdrawalAddressProtector;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ExportPlatformWithdrawals
{
    public const MAX_ROWS = 10000;

    public function execute(AdminUser $actor, array $filters): string
    {
        app(AssetAccess::class)->platform($actor, 'withdrawals.read');
        app(AssetAccess::class)->platform($actor, 'withdrawals.review');

        // Build the complete file before returning it: failed decrypts/audits must not
        // produce a successful partial download. No plaintext files are persisted.
        return DB::transaction(function () use ($actor, $filters): string {
            $at = CarbonImmutable::now();
            $rows = app(PlatformFundsQuery::class)->filtered('withdrawal', $filters)
                ->leftJoin('withdrawal_orders as w', fn ($j) => $j->on('w.id', '=', 'o.id')->on('w.tenant_id', '=', 'o.tenant_id')->on('w.user_id', '=', 'o.user_id')->where('o.source', 'primary'))
                ->leftJoin('withdrawal_destinations as d', fn ($j) => $j->on('d.id', '=', 'w.withdrawal_destination_id')->on('d.tenant_id', '=', 'o.tenant_id')->on('d.user_id', '=', 'o.user_id'))
                ->leftJoin('asset_withdrawal_orders as a', fn ($j) => $j->on('a.id', '=', 'o.id')->on('a.tenant_id', '=', 'o.tenant_id')->on('a.user_id', '=', 'o.user_id')->where('o.source', 'asset'))
                ->leftJoin('ledger_entries as l', fn ($j) => $j->on('l.id', '=', 'a.ledger_entry_id')->on('l.tenant_id', '=', 'o.tenant_id'))
                ->addSelect('t.timezone')
                ->selectRaw('COALESCE(w.amount,a.amount)::text AS amount, COALESCE(w.fee_amount,a.fee_amount)::text AS fee,
                    COALESCE(d.address_ciphertext,a.address) AS address_ciphertext,
                    COALESCE(w.submitted_tx_hash,a.submitted_tx_hash) AS tx_hash,
                    COALESCE(w.blockchain_confirmed_at,l.created_at) AS completed_at')
                ->limit(self::MAX_ROWS + 1)->get();
            if ($rows->count() > self::MAX_ROWS) {
                throw ValidationException::withMessages(['export' => 'Too many withdrawals. Narrow the filters to 10,000 records or fewer.']);
            }

            $stream = fopen('php://memory', 'w+');
            try {
                fwrite($stream, "\xEF\xBB\xBF");
                $this->csv($stream, ['公司', '账号', '邮箱', '订单号', '订单来源', '币种', '网络', '提现金额（含手续费）', '手续费', '实际出金金额', '收款地址', '状态', '申请时间', '成功时间', '公司时区', '交易哈希', '累计提现成功金额（同币种含手续费）', '累计成功实际出金金额（同币种）', '累计提现成功笔数（同币种）', '当前代理等级', '是否合伙人', '导出时间（UTC）']);
                $profiles = [];
                foreach ($rows->groupBy('tenant_id') as $tenant => $orders) {
                    $users = $orders->pluck('user_id')->unique()->all();
                    $ranks = app(ManualPromotion::class)->query($tenant, $at)->whereIn('effective_user.id', $users)->get()->keyBy('user_id');
                    $partners = DB::table('partner_configurations')->where('tenant_id', $tenant)->whereIn('user_id', $users)->where('enabled', true)->pluck('user_id')->flip();
                    // Lifetime successful totals intentionally ignore list status/search/network.
                    // Native currencies are never combined or converted.
                    $totals = DB::table('withdrawal_orders')->where('tenant_id', $tenant)->whereIn('user_id', $users)->where('status', 'SUCCEEDED')
                        ->selectRaw('user_id,asset_code,amount,fee_amount')
                        ->unionAll(DB::table('asset_withdrawal_orders')->where('tenant_id', $tenant)->whereIn('user_id', $users)->where('status', 'COMPLETED')->selectRaw('user_id,asset_code,amount,fee_amount'));
                    $totals = DB::query()->fromSub($totals, 'success')->groupBy('user_id', 'asset_code')
                        ->selectRaw('user_id,asset_code,SUM(amount)::text AS gross,SUM(amount-fee_amount)::text AS net,COUNT(*) AS count')
                        ->get()->keyBy(fn ($r) => $r->user_id.':'.$r->asset_code);
                    $profiles[$tenant] = [$ranks, $partners, $totals];
                    app(AuditLogger::class)->record($tenant, 'ADMIN', $actor->id, 'WITHDRAWALS_EXPORTED', 'withdrawal_export', null, null, [
                        'count' => $orders->count(), 'orders' => $orders->map(fn ($r) => ['source' => $r->source, 'id' => $r->id])->values()->all(),
                        'includes_full_addresses' => true, 'exported_at' => $at->toIso8601String(),
                    ]);
                }
                foreach ($rows as $row) {
                    [$ranks, $partners, $totals] = $profiles[$row->tenant_id];
                    $total = $totals->get($row->user_id.':'.$row->asset_code);
                    $this->csv($stream, [
                        $row->company_name, $row->account_id, $row->email, $row->id, $row->source === 'primary' ? 'TRON' : '多资产',
                        $row->asset_code, $row->network, $this->decimal($row->amount), $this->decimal($row->fee),
                        $this->decimal((string) BigDecimal::of($row->amount)->minus($row->fee)),
                        app(WithdrawalAddressProtector::class)->decrypt($row->address_ciphertext), $row->status,
                        CarbonImmutable::parse($row->ordered_at)->setTimezone($row->timezone)->toIso8601String(),
                        $row->completed_at ? CarbonImmutable::parse($row->completed_at)->setTimezone($row->timezone)->toIso8601String() : '',
                        $row->timezone, $row->tx_hash ?? '', $this->decimal($total?->gross ?? '0'), $this->decimal($total?->net ?? '0'),
                        (string) ($total?->count ?? 0), (string) ($ranks->get($row->user_id)?->rank ?? 0), $partners->has($row->user_id) ? '是' : '否', $at->toIso8601String(),
                    ]);
                }
                rewind($stream);

                return stream_get_contents($stream);
            } finally {
                fclose($stream);
            }
        });
    }

    private function decimal(string $value): string
    {
        return (string) BigDecimal::of($value)->strippedOfTrailingZeros();
    }

    private function csv($stream, array $cells): void
    {
        // Do not allow account/email/company text to become spreadsheet formulas.
        $cells = array_map(fn ($v) => preg_match('/^[\s\x00-\x1f]*[=+@\-]/u', $v) ? "'".$v : $v, $cells);
        fputcsv($stream, $cells, ',', '"', '', "\r\n");
    }
}
