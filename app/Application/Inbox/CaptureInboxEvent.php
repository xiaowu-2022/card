<?php

namespace App\Application\Inbox;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Card\Models\CardManagementOrder;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Explicit adapter for newly committed business evidence; never scans old audit history. */
final class CaptureInboxEvent
{
    public function __construct(private InboxWriter $writer) {}

    public function audit(AuditLog $audit): void
    {
        $map = [
            'WALLET_TOPUP_CREDITED' => ['wallet_topup_orders', 'deposit', '/wallet/top-ups/%s/return', 'CREDITED'],
            'ASSET_DEPOSIT_CREDITED' => ['asset_deposit_orders', 'deposit', '/funds', 'CREDITED'],
            'ASSET_DEPOSIT_MANUALLY_CONFIRMED' => ['asset_deposit_orders', 'deposit', '/funds', 'CREDITED'],
            'WALLET_TRANSFER_COMPLETED' => ['wallet_transfers', 'transfer_sent', '/wallet/transfers/%s', null],
            'WITHDRAWAL_SUCCEEDED' => ['withdrawal_orders', 'withdrawal_success', '/wallet/withdrawals/%s', 'SUCCEEDED'],
            'WITHDRAWAL_REJECTED' => ['withdrawal_orders', 'withdrawal_rejected', '/wallet/withdrawals/%s', 'REJECTED'],
            'ASSET_WITHDRAWAL_COMPLETED' => ['asset_withdrawal_orders', 'withdrawal_success', '/funds', 'COMPLETED'],
            'ASSET_WITHDRAWAL_REJECTED' => ['asset_withdrawal_orders', 'withdrawal_rejected', '/funds', 'REJECTED'],
            'KYC_APPLICATION_APPROVED' => ['kyc_applications', 'kyc_approved', '/kyc', null],
            'KYC_APPLICATION_REJECTED' => ['kyc_applications', 'kyc_rejected', '/kyc', null],
            'CARD_ISSUE_SUCCEEDED' => ['card_issue_orders', 'card_issue_success', '/cards', 'SUCCEEDED'],
            'CARD_ISSUE_FAILED' => ['card_issue_orders', 'card_issue_failed', '/cards', 'FAILED'],
            'CARD_ACTIVATION_CONFIRMED' => ['user_cards', 'card_activation_success', '/cards', null],
            'SECURITY_DEPOSIT_FUNDED' => ['wallets', 'deposit_funded', '/security-deposit', null],
            'SECURITY_DEPOSIT_REFUNDED' => ['security_deposit_refund_requests', 'deposit_refunded', '/security-deposit', 'COMPLETED'],
            'PROMOTION_FEE_PAID' => ['paid_promotion_orders', 'membership', '/promotion/membership', 'COMPLETED'],
            'PROMOTION_REBATE_AUTO_COMPLETED' => ['paid_promotion_rebates', 'rebate', '/promotion', 'APPROVED'],
            'WEALTH_DEPOSIT' => ['wealth_orders', 'wealth_deposit', '/wealth/orders/%s', 'ACTIVE'],
            'WEALTH_INTEREST' => ['wealth_orders', 'wealth_interest', '/wealth/orders/%s', null],
            'WEALTH_CANCEL' => ['wealth_orders', 'wealth_return', '/wealth/orders/%s', 'CANCELLED'],
            'WEALTH_MATURITY' => ['wealth_orders', 'wealth_return', '/wealth/orders/%s', 'MATURED'],
            'WEALTH_REDEEM' => ['wealth_orders', 'wealth_return', '/wealth/orders/%s', 'MATURED'],
            'WEALTH_RENEW' => ['wealth_orders', 'wealth_renew', '/wealth/orders/%s', 'MATURED'],
        ];
        if (! $audit->tenant_id || ! isset($map[$audit->action])) {
            return;
        }
        [$table, $template, $href, $status] = $map[$audit->action];
        $row = DB::table($table)->where('tenant_id', $audit->tenant_id)->where('id', $audit->resource_id)->first();
        if (! $row || ($status && $row->status !== $status)) {
            return;
        }
        if ($template === 'kyc_approved' && $row->review_status !== 'APPROVED') {
            return;
        }
        if ($template === 'kyc_rejected' && $row->review_status !== 'REJECTED') {
            return;
        }
        if ($template === 'card_activation_success' && ($row->form_factor !== 'physical_card' || $row->provider_status !== 'normal')) {
            return;
        }
        $data = [];
        $asset = $row->asset_code ?? $row->wallet_asset ?? 'USDT';
        $amount = $row->amount ?? $row->initial_load_amount ?? $row->principal ?? null;
        $identity = $row->id;
        if ($template === 'withdrawal_success') {
            $amount = $row->receive_amount ?? (string) BigDecimal::of($row->amount)->minus($row->fee_amount);
        }
        if ($template === 'deposit_funded') {
            $amount = $audit->after_data['amount'];
            $asset = $audit->after_data['asset'];
            $identity = $audit->after_data['ledger_entry_id'];
        }
        if ($template === 'wealth_interest') {
            $part = DB::table('wealth_installments')->where('tenant_id', $audit->tenant_id)->where('order_id', $row->id)
                ->where('ledger_entry_id', $audit->after_data['entry_id'])->first();
            if (! $part || ! $part->settled_at) {
                return;
            }
            $identity = $part->id;
            $amount = $part->amount;
        }
        if ($template === 'wealth_return') {
            $amount = $row->returned;
        }
        if ($template === 'membership') {
            $amount = $row->settlement_total;
        }
        if ($amount !== null) {
            $data = ['amount' => (string) $amount, 'asset' => $asset];
        }
        $href = sprintf($href, $row->id);
        $user = $row->user_id ?? $row->sender_user_id;
        $this->writer->record($audit->tenant_id, $user, $template.':'.$identity, $template, $data, $href, $audit->created_at);
        if ($template === 'transfer_sent') {
            $this->writer->record($audit->tenant_id, $row->recipient_user_id, 'transfer_received:'.$identity, 'transfer_received', $data, $href, $audit->created_at);
        }
    }

    public function card(CardManagementOrder $order): void
    {
        if (! $order->wasChanged('status') || ! in_array($order->status, ['SUCCEEDED', 'FAILED'], true)) {
            return;
        }
        $kind = ['LOAD' => 'load', 'RETURN' => 'return', 'CANCEL_RETURN' => 'return', 'CANCEL' => 'cancel'][$order->kind] ?? null;
        if (! $kind) {
            return;
        }
        $template = 'card_'.$kind.($order->status === 'SUCCEEDED' ? '_success' : '_failed');
        $params = [];
        if ($kind === 'load') {
            $params = ['amount' => (string) ($order->requested_amount ?? $order->amount), 'asset' => 'USD'];
        }
        if ($kind === 'return') {
            $params = ['amount' => (string) ($order->arrival_amount ?? $order->amount), 'asset' => 'USDT'];
        }
        $this->writer->record($order->tenant_id, $order->user_id, $template.':'.$order->id, $template, $params, '/cards');
    }
}
