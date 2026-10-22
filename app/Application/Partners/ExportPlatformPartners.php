<?php

namespace App\Application\Partners;

use App\Application\User\PlatformUserListIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ExportPlatformPartners
{
    public function execute(Request $request, bool $amounts): string
    {
        $query = app(PlatformPartnerQuery::class)->query($request, $request->query('company', $request->query('tenant')), $amounts);
        if ((clone $query)->count() > 10000) {
            throw ValidationException::withMessages(['export' => '合伙人超过 10,000 条，请缩小筛选范围后导出。']);
        }
        $columns = [
            '公司' => 'company_name', '账号' => 'account_id', '姓名' => 'display_name', '邮箱' => 'email',
            '分成比例 %' => 'share_percent', '注册时间 UTC+8' => 'registered_at',
            '代理等级' => 'rank', '直推人账号' => 'directReferrer.accountId', '直推人姓名' => 'directReferrer.displayName',
        ];
        if ($amounts) {
            foreach (['advance' => '累计垫资', 'subordinateAdvances' => '下级垫资', 'annualSubsidy' => '补贴年费', 'stock' => '库存', 'theoretical' => '理论余额', 'actual' => '实际余额', 'difference' => '合伙人余额差额', 'reimbursement' => '报销'] as $key => $label) {
                $columns[$label.' USDT'] = 'summary.'.$key;
            }
            foreach (['deposits' => '普通会员保证金收入', 'annual' => '年费收入', 'depositRefunds' => '普通会员保证金退款', 'activation' => '激活佣金', 'rebates' => '年费退款', 'annualCommission' => '年费佣金'] as $key => $label) {
                foreach (['today' => '今日', '3' => '前3日均', '7' => '前7日均', '15' => '前15日均', '30' => '前30日均'] as $period => $title) {
                    $columns[$label.' '.$title.' USDT'] = 'summary.daily.'.$key.'.'.$period;
                }
            }
            foreach (['remaining' => '剩余退款风险', 'refund3' => '3日内退款', 'refund10' => '10日内退款'] as $key => $label) {
                $columns[$label.' USDT'] = 'summary.alerts.'.$key;
            }
        }
        $columns['导出时间 UTC+8'] = 'exported_at';
        $at = CarbonImmutable::now('Asia/Shanghai')->format('Y-m-d H:i:s');
        $stream = fopen('php://memory', 'w+');
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            $this->csv($stream, array_keys($columns));
            $query->select(PlatformPartnerQuery::COLUMNS)->chunk(20, function ($rows) use ($amounts, $columns, $at, $stream) {
                $summaries = $amounts ? app(PartnerListSummary::class)->forRows($rows->all()) : [];
                $identities = $amounts ? [] : $rows->groupBy('tenant_id')->map(fn ($group, $tenant) => app(PlatformUserListIdentity::class)->forUsers($group->map(fn ($p) => (object) ['id' => $p->user_id, 'tenant_id' => $tenant])->all()));
                foreach ($rows as $partner) {
                    $row = (array) $partner + ($summaries[$partner->id] ?? $identities[$partner->tenant_id][$partner->tenant_id.':'.$partner->user_id] ?? []);
                    $row['registered_at'] = CarbonImmutable::parse($row['registered_at'])->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s');
                    $row['rank'] = ! empty($row['promotionRank']) ? '万事达 '.$row['promotionRank'].' 级' : (! empty($row['ordinaryMember']) ? '普通会员' : '注册会员');
                    $row['exported_at'] = $at;
                    $this->csv($stream, array_map(fn ($path) => data_get($row, $path) ?? (str_starts_with($path, 'summary.') ? '未知' : '—'), $columns));
                }
            });
            rewind($stream);

            return stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    private function csv($stream, array $cells): void
    {
        $cells = array_map(function ($value) {
            $value = (string) $value;

            return preg_match('/^[\s\x00-\x1f]*[=+@\-]/u', $value) ? "'".$value : $value;
        }, $cells);
        fputcsv($stream, $cells, ',', '"', '', "\r\n");
    }
}
