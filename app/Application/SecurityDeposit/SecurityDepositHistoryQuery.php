<?php

namespace App\Application\SecurityDeposit;

use App\Domain\SecurityDeposit\Models\SecurityDepositRefundRequest;
use App\Domain\User\Models\User;

final readonly class SecurityDepositHistoryQuery
{
    public function execute(string $tenantId, string $userId, int $page = 1): array
    {
        User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
        $rows = SecurityDepositRefundRequest::query()
            ->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(20, ['*'], 'page', $page);

        return [
            'data' => $rows->getCollection()->map(fn (SecurityDepositRefundRequest $row) => [
                'id' => $row->id, 'amount' => $row->amount, 'asset' => $row->asset_code,
                'state' => ['CHECKING' => 'pending', 'COMPLETED' => 'completed', 'CANCELLED' => 'cancelled'][$row->status],
                'requestedAt' => $row->created_at->toIso8601String(),
            ])->all(),
            'currentPage' => $rows->currentPage(), 'lastPage' => $rows->lastPage(),
        ];
    }
}
