<?php

namespace App\Application\User\Snapshots;

/** Only reporting inputs. Never retain credentials, KYC materials or provider secrets. */
final class UserSnapshotSources
{
    public const COLUMNS = [
        'users' => ['id', 'tenant_id', 'account_id', 'email', 'phone', 'status', 'created_at', 'last_login_at', 'support_remark', 'support_remark_revision', 'withdrawal_blocked', 'deposit_refund_blocked', 'card_transfer_blocked', 'wallet_transfer_blocked'],
        'user_profiles' => ['id', 'tenant_id', 'user_id', 'display_name'],
        'tenants' => ['id', 'name', 'timezone'],
        'support_user_agents' => ['tenant_id', 'user_id', 'enabled', 'revision'],
        'partner_configurations' => ['id', 'tenant_id', 'user_id', 'enabled'],
        'promotion_members' => ['id', 'tenant_id', 'user_id', 'inviter_id', 'invitation_code'],
        'manual_promotion_adjustments' => ['id', 'tenant_id', 'user_id', 'rank', 'percent', 'reward', 'revision', 'effective_rank', 'created_at'],
        'paid_promotion_cycles' => ['id', 'tenant_id', 'user_id', 'rank', 'percent', 'reward', 'revision', 'starts_at', 'ends_at'],
        'paid_promotion_orders' => ['id', 'tenant_id', 'user_id', 'status', 'rank', 'settlement_total', 'ledger_entry_id', 'completed_at'],
        'wallets' => ['id', 'tenant_id', 'user_id', 'asset_code', 'status'],
        'ledger_accounts' => ['id', 'tenant_id', 'user_id', 'wallet_id', 'card_id', 'asset_code', 'account_type', 'balance', 'status'],
        'ledger_entries' => ['id', 'tenant_id', 'asset_code', 'event_type', 'event_key', 'sealed_at', 'posted_at', 'created_at'],
        'ledger_postings' => ['id', 'tenant_id', 'ledger_entry_id', 'ledger_account_id', 'delta'],
        'wallet_topup_orders' => ['id', 'tenant_id', 'user_id', 'asset_code', 'status', 'actual_received_amount', 'amount', 'manual_receipt_type'],
        'asset_deposit_orders' => ['id', 'tenant_id', 'user_id', 'asset_code', 'status', 'actual_received_amount', 'amount', 'manual_receipt_type'],
        'deposit_receipt_type_changes' => ['id', 'tenant_id', 'source', 'order_id', 'after_type', 'after_journal_id', 'revision'],
        'withdrawal_orders' => ['id', 'tenant_id', 'user_id', 'asset_code', 'status', 'amount'],
        'asset_withdrawal_orders' => ['id', 'tenant_id', 'user_id', 'asset_code', 'status', 'amount'],
        'security_deposit_refund_requests' => ['id', 'tenant_id', 'user_id', 'status', 'cancel_requested_at'],
        'wallet_transfers' => ['id', 'tenant_id', 'sender_user_id', 'recipient_user_id', 'asset_code', 'amount'],
        'paid_promotion_shares' => ['id', 'tenant_id', 'user_id', 'event_id', 'ledger_entry_id', 'amount', 'rank', 'depth', 'rate', 'standard', 'covered'],
        'paid_promotion_events' => ['id', 'tenant_id', 'user_id', 'kind', 'source_rank', 'source_id', 'amount', 'occurred_at'],
        'commission_awards' => ['id', 'tenant_id', 'user_id', 'funding_event_id', 'ledger_entry_id', 'amount'],
        'promotion_funding_events' => ['id', 'tenant_id', 'user_id', 'amount', 'funded_at'],
        'manual_commission_adjustments' => ['id', 'tenant_id', 'user_id', 'ledger_entry_id', 'direction', 'amount'],
        'commission_adjustment_classifications' => ['id', 'tenant_id', 'user_id', 'adjustment_id', 'kind'],
        'user_cards' => ['id', 'tenant_id', 'user_id', 'card_product_id', 'card_issue_order_id', 'provider_balance', 'card_currency', 'archived_at', 'provider_balance_synced_at', 'created_at', 'last4'],
        'card_products' => ['id', 'name'],
        'tenant_card_product_configs' => ['id', 'tenant_id', 'card_product_id', 'display_name'],
        'card_issue_orders' => ['id', 'tenant_id', 'user_id', 'status', 'initial_load_amount'],
        'card_management_orders' => ['id', 'tenant_id', 'user_id', 'card_id', 'status', 'kind', 'arrival_amount', 'manual_funding_amount'],
        'card_overflow_movements' => ['id', 'tenant_id', 'user_id', 'card_id', 'kind', 'amount'],
        'asset_market_snapshots' => ['id', 'provider', 'usd_prices', 'usdt_rates', 'observed_at', 'created_at'],
    ];
}
