<?php

namespace App\Application\Payment;

use App\Application\Assets\AssetAccess;
use App\Application\Partners\PartnerManagement;
use App\Application\Partners\UserAdvances;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\ValueObjects\Money;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Called inside the order's locked settlement transaction. */
final class ManualDepositReceipt
{
    public function amount(Model $order, ?string $amount): string
    {
        try {
            if ($amount === null || ! preg_match('/^\d{1,12}(?:\.\d{1,18})?$/', $amount)) {
                throw new \InvalidArgumentException;
            }
            $money = Money::of($amount, $order->asset_code);
            if (! $money->isPositive()) {
                throw new \InvalidArgumentException;
            }

            return $money->amount();
        } catch (\InvalidArgumentException $e) {
            throw new DomainException('AMOUNT_INVALID', 'Enter a valid positive actual received amount.', 422);
        }
    }

    public function validateType(string $type): void
    {
        if (! in_array($type, ['ACTUAL', 'ADVANCE'], true)) {
            throw new DomainException('VALIDATION_FAILED', 'Invalid receipt type.', 422);
        }
    }

    public function checkRetry(Model $order, string $type, string $request, string $requestColumn, AdminUser $actor, string $amount): void
    {
        $this->validateType($type);
        if ($request === $order->$requestColumn && $order->manual_confirmed_by === $actor->id
            && (($order->manual_receipt_type ?? 'ACTUAL') !== $type || ! BigDecimal::of($order->actual_received_amount ?? $order->amount)->isEqualTo($amount))) {
            throw new DomainException('IDEMPOTENCY_CONFLICT', 'This request was used with a different receipt type or amount.', 409);
        }
    }

    public function attributes(Model $order, AdminUser $actor, string $type, string $amount, bool $classificationChange = false): array
    {
        $this->validateType($type);
        $journalId = null;
        $userJournalId = null;
        if ($type === 'ADVANCE') {
            app(AssetAccess::class)->platform($actor, 'partners.manage');
            app(AssetAccess::class)->settlementOwners($order->tenant_id, $order->user_id);
            if ($order->asset_code !== 'USDT') {
                throw new DomainException('TOPUP_CONFIRMATION_NOT_ALLOWED', 'Advances require USDT.', 422);
            }
            $partner = DB::table('partner_configurations')->where('tenant_id', $order->tenant_id)->where('user_id', $order->user_id)->lockForUpdate()->first();
            $input = [
                'amount' => $amount, 'confirmed' => true,
                'business_date' => now()->setTimezone('Asia/Shanghai')->format('Y-m-d'),
                'note' => ($classificationChange ? 'Deposit receipt: ' : 'Manual deposit: ').$order->getTable().'/'.$order->id,
                'request_id' => (string) Str::uuid(),
            ];
            if ($partner?->enabled) {
                $journalId = app(PartnerManagement::class)->journal($actor, $order->tenant_id, $partner->id, ['kind' => 'ADVANCE'] + $input)->id;
            } else {
                $userJournalId = app(UserAdvances::class)->record($actor, $order->tenant_id, $order->user_id, $input)->id;
            }
        }

        return ['actual_received_amount' => $amount, 'manual_receipt_type' => $type, 'advance_journal_id' => $journalId, 'user_advance_journal_id' => $userJournalId];
    }
}
