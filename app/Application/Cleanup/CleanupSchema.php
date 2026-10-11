<?php

namespace App\Application\Cleanup;

use Illuminate\Support\Facades\DB;
use LogicException;

/** Deliberate business-data allowlist. Configuration and audit are never purgeable. */
final class CleanupSchema
{
    public const TABLES = [
        'users', 'user_profiles', 'user_preferences', 'user_contact_changes', 'user_password_resets',
        'consumer_device_tokens', 'consumer_presence', 'platform_user_creations', 'registration_challenges',
        'wallets', 'ledger_accounts', 'ledger_entries', 'ledger_postings', 'wallet_adjustments',
        'wallet_topup_orders', 'payment_provider_transactions', 'payment_provider_events',
        'asset_deposit_orders', 'asset_withdrawal_orders', 'asset_exchange_orders', 'asset_chain_observations',
        'withdrawal_orders', 'withdrawal_destinations', 'withdrawal_transaction_attempts', 'withdrawal_fee_valuations',
        'wallet_transfers', 'initial_deposit_intents', 'security_deposit_refund_requests',
        'promotion_members', 'promotion_assignment_archive', 'promotion_invitation_aliases',
        'promotion_invitation_changes', 'promotion_invitation_reservations', 'referrer_changes',
        'promotion_funding_events', 'commission_awards', 'commission_transfers', 'commission_balance_consolidations',
        'manual_commission_adjustments', 'commission_adjustment_classifications', 'manual_promotion_adjustments',
        'paid_promotion_orders', 'paid_promotion_cycles', 'paid_promotion_events', 'paid_promotion_shares', 'paid_promotion_rebates',
        'account_activations', 'account_activation_relations', 'activation_count_snapshots',
        'partner_configurations', 'partner_journal_entries', 'deposit_receipt_type_changes',
        'user_cards', 'provider_cardholders', 'card_recipient_applications', 'card_issue_orders',
        'card_management_orders', 'card_overflow_movements', 'card_transactions', 'card_activation_attempts',
        'card_provider_events', 'card_transaction_sync_batches', 'card_transaction_sync_items',
        'wealth_orders', 'wealth_installments',
        'kyc_applications', 'identity_records', 'kyc_processing_retries', 'direct_image_uploads',
        'inbox_events', 'inbox_receipts', 'support_user_agents', 'support_user_quick_replies', 'support_agent_reads',
        'support_conversations', 'support_messages', 'support_message_revisions', 'support_message_revision_reads', 'support_transitions',
    ];

    public const ORDERS = [
        'wallet_topup_orders', 'asset_deposit_orders', 'withdrawal_orders', 'asset_withdrawal_orders',
        'wallet_transfers', 'asset_exchange_orders', 'security_deposit_refund_requests',
        'initial_deposit_intents', 'promotion_funding_events', 'paid_promotion_orders', 'paid_promotion_rebates',
        'wealth_orders', 'wallet_adjustments',
    ];

    public const CARDS = [
        'user_cards', 'provider_cardholders', 'card_recipient_applications', 'card_issue_orders',
        'card_management_orders', 'card_overflow_movements', 'card_transactions', 'card_activation_attempts', 'card_provider_events',
    ];

    public const COMMISSIONS = [
        'commission_awards', 'paid_promotion_shares', 'manual_commission_adjustments',
        'commission_transfers', 'commission_balance_consolidations', 'commission_adjustment_classifications',
    ];

    public function metadata(): array
    {
        $tables = [];
        foreach (DB::select("SELECT table_name,column_name FROM information_schema.columns WHERE table_schema='public' ORDER BY ordinal_position") as $row) {
            $tables[$row->table_name]['columns'][] = $row->column_name;
        }
        foreach (DB::select("SELECT c.relname AS table_name,a.attname AS column_name FROM pg_index i JOIN pg_class c ON c.oid=i.indrelid JOIN pg_namespace n ON n.oid=c.relnamespace JOIN LATERAL unnest(i.indkey) WITH ORDINALITY k(num,pos) ON true JOIN pg_attribute a ON a.attrelid=c.oid AND a.attnum=k.num WHERE i.indisprimary AND n.nspname='public' ORDER BY c.relname,k.pos") as $row) {
            $tables[$row->table_name]['keys'][] = $row->column_name;
        }

        return $tables;
    }

    public static function category(string $table): string
    {
        return match (true) {
            str_starts_with($table, 'ledger_'), $table === 'wallets' => 'Funds and ledger data',
            in_array($table, self::CARDS, true), str_starts_with($table, 'card_transaction_sync_') => 'Cards',
            in_array($table, self::ORDERS, true), $table === 'wealth_installments' => 'Orders',
            in_array($table, self::COMMISSIONS, true), $table === 'paid_promotion_events' => 'Commissions',
            str_contains($table, 'promotion'), str_starts_with($table, 'partner_'), str_starts_with($table, 'account_activation'), $table === 'referrer_changes', $table === 'activation_count_snapshots' => 'Promotion and partner data',
            str_starts_with($table, 'kyc_'), $table === 'identity_records', $table === 'direct_image_uploads' => 'Verification data',
            str_starts_with($table, 'support_'), str_starts_with($table, 'inbox_') => 'Support and notifications',
            default => 'Account and related data',
        };
    }

    public function foreignKeys(): array
    {
        return array_map(fn ($r) => [
            'child' => $r->child, 'parent' => $r->parent,
            'columns' => json_decode($r->columns, true), 'parentColumns' => json_decode($r->parent_columns, true),
        ], DB::select("SELECT ch.relname AS child,pa.relname AS parent,json_agg(ca.attname ORDER BY k.pos)::text AS columns,json_agg(aa.attname ORDER BY k.pos)::text AS parent_columns FROM pg_constraint c JOIN pg_class ch ON ch.oid=c.conrelid JOIN pg_class pa ON pa.oid=c.confrelid JOIN pg_namespace n ON n.oid=ch.relnamespace JOIN LATERAL unnest(c.conkey,c.confkey) WITH ORDINALITY k(child_num,parent_num,pos) ON true JOIN pg_attribute ca ON ca.attrelid=ch.oid AND ca.attnum=k.child_num JOIN pg_attribute aa ON aa.attrelid=pa.oid AND aa.attnum=k.parent_num WHERE c.contype='f' AND n.nspname='public' GROUP BY c.oid,ch.relname,pa.relname ORDER BY ch.relname,pa.relname"));
    }

    public static function quoted(string $table): string
    {
        if (! in_array($table, self::TABLES, true)) {
            throw new LogicException('Unregistered cleanup table.');
        }

        return '"'.$table.'"';
    }
}
