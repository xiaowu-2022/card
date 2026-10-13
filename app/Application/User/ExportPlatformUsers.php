<?php

namespace App\Application\User;

use App\Domain\Assets\AssetCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class ExportPlatformUsers
{
    public const MAX_ROWS = 10000;

    public function execute(array $filters, array $access): string
    {
        $read = fn ($page) => app(PlatformUserQuery::class)->paginate(
            $filters['company'] ?? null, $filters['search'] ?? null, $filters['status'] ?? null,
            $access['financialAccess'], $filters['support'] ?? null, null, 'Disabled', $filters['referrer'] ?? null,
            withRestrictions: $access['canManageRestrictions'], perPage: 100, pageNumber: $page);
        $first = $read(1);
        if ($first->total() > self::MAX_ROWS) {
            throw ValidationException::withMessages(['export' => 'Too many users. Narrow the filters to 10,000 records or fewer.']);
        }
        $at = $filters['as_of'] ?? CarbonImmutable::now('Asia/Shanghai')->format('Y-m-d\TH:i:s');
        $columns = [
            '公司' => fn ($r) => $r['companyName'], '账号' => fn ($r) => $r['accountId'],
            '姓名' => fn ($r) => $r['displayName'], '邮箱' => fn ($r) => $r['email'],
            '客户备注' => fn ($r) => $r['remark'], '客服资格' => fn ($r) => $r['supportAgent'] ? '启用' : '未启用',
            '代理等级' => fn ($r) => $r['promotionRank'] ? '万事达 '.$r['promotionRank'].' 级' : ($r['ordinaryMember'] ? '普通会员' : '注册会员'),
            '直推人账号' => fn ($r) => $r['directReferrer']['accountId'] ?? null,
            '直推人姓名' => fn ($r) => $r['directReferrer']['displayName'] ?? null,
        ];
        $financial = $access['financialAccess'];
        if ($financial['balances']) {
            foreach (AssetCatalog::ASSETS as $asset) {
                $columns['可用余额 '.$asset] = fn ($r) => $this->asset($r['wallets'] ?? [], $asset, 'available');
            }
        }
        if ($financial['funds'] ?? false) {
            $columns['预估总资金 USDT'] = fn ($r) => $r['financialSummary']['estimate'] ?? '未知';
            $columns['卡余额 USDT'] = fn ($r) => $r['financialSummary']['cardBalance'] ?? '未知';
        }
        if ($financial['receipts']) {
            foreach (AssetCatalog::ASSETS as $asset) {
                $columns['实际到账金额 '.$asset] = fn ($r) => $this->asset($r['receiptTotals'] ?? [], $asset, 'actual');
            }
        }
        if ($financial['commission']) {
            foreach (['annualCommission' => '年费佣金', 'activationCommission' => '激活佣金', 'annualExpense' => '年费支出'] as $field => $label) {
                $columns[$label.' USDT'] = fn ($r) => $r['financialSummary'][$field] ?? '未知';
            }
            foreach (['incoming' => '转入', 'outgoing' => '转出'] as $direction => $label) {
                foreach (AssetCatalog::ASSETS as $asset) {
                    $columns[$label.' '.$asset] = fn ($r) => $this->asset($r['financialSummary']['transfers'][$direction] ?? [], $asset, 'amount', '0');
                }
            }
        }
        if ($financial['withdrawals']) {
            foreach (AssetCatalog::ASSETS as $asset) {
                $columns['转出资金 '.$asset] = fn ($r) => $this->asset($r['financialSummary']['withdrawals'] ?? [], $asset, 'amount', '0');
            }
        }
        $columns['状态'] = fn ($r) => ['ACTIVE' => '正常', 'SUSPENDED' => '暂停', 'DISABLED' => '禁用'][$r['status']] ?? $r['status'];
        if ($access['canManageRestrictions']) {
            foreach (['withdrawal_blocked' => '禁止提现', 'deposit_refund_blocked' => '禁止保证金退款', 'card_transfer_blocked' => '禁止卡充值', 'wallet_transfer_blocked' => '禁止转出'] as $field => $label) {
                $columns[$label] = fn ($r) => ($r['operationRestrictions'][$field] ?? false) ? '是' : '否';
            }
        }
        $columns['注册时间 UTC+8'] = fn ($r) => $this->time($r['createdAt']);
        $columns['最后登录时间 UTC+8'] = fn ($r) => $this->time($r['lastLoginAt']);
        $columns['快照时间 UTC+8'] = fn ($r) => $at;
        $stream = fopen('php://memory', 'w+');
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            $this->csv($stream, array_keys($columns));
            for ($number = 1; $number <= $first->lastPage(); $number++) {
                $page = $number === 1 ? $first : $read($number);
                foreach ($page->items() as $row) {
                    $this->csv($stream, array_map(fn ($column) => $column($row), $columns));
                }
            }
            rewind($stream);

            return stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    private function asset(array $items, string $asset, string $field, string $missing = '—'): string
    {
        foreach ($items as $item) {
            if ($item['asset'] === $asset) {
                return $item[$field] ?? '未知';
            }
        }

        return $missing;
    }

    private function time(?string $time): string
    {
        return $time ? CarbonImmutable::parse($time)->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') : '—';
    }

    private function csv($stream, array $cells): void
    {
        $cells = array_map(function ($value) {
            $value = (string) ($value ?? '—');

            return preg_match('/^[\s\x00-\x1f]*[=+@\-]/u', $value) ? "'".$value : $value;
        }, $cells);
        fputcsv($stream, $cells, ',', '"', '', "\r\n");
    }
}
