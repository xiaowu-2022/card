<?php

namespace App\Application\Payment;

use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Payment\Enums\WalletTopupStatus;
use App\Domain\Payment\Models\WalletTopupOrder;
use Illuminate\Support\Facades\DB;

final class PaymentLedgerReconciliationService
{
    /** @return list<array{tenant_id:string,order_id:string,issue:string}> */
    public function mismatches(?string $tenantId = null): array
    {
        $mismatches = [];
        WalletTopupOrder::query()->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->where('status', WalletTopupStatus::Credited->value)->orderBy('id')->chunkById(200, function ($orders) use (&$mismatches): void {
                foreach ($orders as $order) {
                    $issue = $this->creditedOrderIssue($order);
                    if ($issue !== null) {
                        $mismatches[] = ['tenant_id' => $order->tenant_id, 'order_id' => $order->id, 'issue' => $issue];
                    }
                }
            });

        $orphans = DB::table('ledger_entries as entries')
            ->leftJoin('wallet_topup_orders as orders', function ($join): void {
                $join->on('orders.id', '=', 'entries.reference_id')->on('orders.tenant_id', '=', 'entries.tenant_id');
            })
            ->where('entries.event_type', 'WALLET_TOPUP_CREDIT')
            ->when($tenantId, fn ($query) => $query->where('entries.tenant_id', $tenantId))
            ->where(fn ($query) => $query->whereNull('orders.id')->orWhereNull('orders.ledger_entry_id')
                ->orWhereColumn('orders.ledger_entry_id', '<>', 'entries.id'))
            ->select(['entries.tenant_id', 'entries.reference_id', 'entries.id'])->orderBy('entries.id')->get();
        foreach ($orphans as $orphan) {
            $mismatches[] = [
                'tenant_id' => $orphan->tenant_id,
                'order_id' => $orphan->reference_id ?? 'UNKNOWN',
                'issue' => "orphan or unlinked top-up ledger entry {$orphan->id}",
            ];
        }

        return $mismatches;
    }

    private function creditedOrderIssue(WalletTopupOrder $order): ?string
    {
        if ($order->paid_at === null || $order->credited_at === null || $order->ledger_entry_id === null) {
            return 'credited order is missing paid/credited time or ledger reference';
        }
        $entry = DB::table('ledger_entries')->where('id', $order->ledger_entry_id)->first();
        if (! $entry) {
            return 'linked ledger entry does not exist';
        }
        if ($entry->tenant_id !== $order->tenant_id || $entry->asset_code !== $order->asset_code
            || $entry->event_type !== 'WALLET_TOPUP_CREDIT' || $entry->event_key !== "wallet_topup:{$order->id}:credit"
            || $entry->reference_type !== 'WALLET_TOPUP_ORDER' || $entry->reference_id !== $order->id || $entry->sealed_at === null) {
            return 'linked ledger entry identity does not match the top-up';
        }

        $postings = DB::table('ledger_postings as postings')->join('ledger_accounts as accounts', 'accounts.id', '=', 'postings.ledger_account_id')
            ->where('postings.ledger_entry_id', $entry->id)
            ->select(['accounts.account_type', 'accounts.wallet_id', 'postings.delta'])->get();
        if ($postings->count() !== 2) {
            return 'top-up ledger entry does not contain exactly two postings';
        }
        $available = $postings->first(fn ($posting) => $posting->account_type === LedgerAccountType::UserAvailable->value
            && $posting->wallet_id === $order->wallet_id && Money::of($posting->delta, $order->asset_code)->amount() === $order->amount);
        $negative = Money::of('-'.$order->amount, $order->asset_code)->amount();
        $clearing = $postings->first(fn ($posting) => $posting->account_type === LedgerAccountType::TenantTopupClearing->value
            && $posting->wallet_id === null && Money::of($posting->delta, $order->asset_code)->amount() === $negative);

        return $available && $clearing ? null : 'top-up ledger entry uses an incorrect account or amount';
    }
}
