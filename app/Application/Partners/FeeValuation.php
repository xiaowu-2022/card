<?php

namespace App\Application\Partners;

use App\Application\Assets\AssetAccess;
use App\Application\Assets\MarketPrices;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Assets\AssetWithdrawalOrder;
use App\Domain\Audit\Services\AuditLogger;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class FeeValuation
{
    /** Called only after exact payout evidence, outside the settlement transaction. */
    public function quote(string $asset, string $fee): array
    {
        if ($asset === 'USDT' || BigDecimal::of($fee)->isZero()) {
            return ['rate' => '1', 'observed_at' => now(), 'source' => 'PARITY'];
        }
        try {
            $prices = app(MarketPrices::class);
            $snapshot = $prices->refresh();
            $rate = (string) $prices->rate($snapshot, $asset);
            if (BigDecimal::of($this->convert($fee, $rate))->isGreaterThanOrEqualTo('1000000000000000000000000000000')) {
                throw new \OverflowException('Fee valuation exceeds reporting storage');
            }

            return ['rate' => $rate, 'observed_at' => $snapshot->observed_at, 'source' => 'OKX'];
        } catch (\Throwable) {
            return ['rate' => null, 'observed_at' => null, 'source' => 'PENDING'];
        }
    }

    /** The new fee record and successful withdrawal commit together; no historical backfill. */
    public function record(AssetWithdrawalOrder $order, array $quote): void
    {
        DB::table('withdrawal_fee_valuations')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $order->tenant_id, 'withdrawal_id' => $order->id,
            'asset_code' => $order->asset_code, 'original_amount' => $order->fee_amount,
            'rate' => $quote['rate'], 'source' => $quote['source'], 'observed_at' => $quote['observed_at'],
            'usdt_amount' => $quote['rate'] === null ? null : $this->convert($order->fee_amount, $quote['rate']),
            'created_at' => now(), 'valued_at' => $quote['rate'] === null ? null : now(),
        ]);
    }

    public function supplement(AdminUser $actor, string $tenant, string $id, array $input): void
    {
        app(AssetAccess::class)->platform($actor, 'partners.manage');
        $data = Validator::make($input, ['rate' => ['required', 'regex:/^\d{1,12}(\.\d{1,18})?$/', 'numeric', 'gt:0'], 'observed_at' => 'required|date|before_or_equal:now', 'evidence' => 'required|string|max:2000', 'request_id' => 'required|uuid'])->validate();
        DB::transaction(function () use ($actor, $tenant, $id, $data) {
            app(AssetAccess::class)->platform($actor, 'partners.manage');
            $row = DB::table('withdrawal_fee_valuations')->where('tenant_id', $tenant)->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($row->source !== 'PENDING') {
                abort_unless($row->source === 'MANUAL' && $row->request_id === $data['request_id'] && BigDecimal::of($row->rate)->isEqualTo($data['rate']) && $row->evidence === $data['evidence'] && CarbonImmutable::parse($row->observed_at)->equalTo(CarbonImmutable::parse($data['observed_at'])), 409);

                return;
            }
            abort_if(BigDecimal::of($this->convert($row->original_amount, $data['rate']))->isGreaterThanOrEqualTo('1000000000000000000000000000000'), 422);
            DB::table('withdrawal_fee_valuations')->where('id', $id)->update($data + ['usdt_amount' => $this->convert($row->original_amount, $data['rate']), 'source' => 'MANUAL', 'actor_id' => $actor->id, 'valued_at' => now()]);
            app(AuditLogger::class)->record($tenant, 'ADMIN', $actor->id, 'WITHDRAWAL_FEE_VALUED', 'withdrawal_fee_valuation', $id, null, $data, $data['request_id']);
        });
    }

    private function convert(string $amount, string $rate): string
    {
        return (string) BigDecimal::of($amount)->multipliedBy($rate)->toScale(8, RoundingMode::HalfUp);
    }
}
