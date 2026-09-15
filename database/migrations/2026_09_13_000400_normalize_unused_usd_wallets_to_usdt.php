<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Approved denomination cleanup, NOT an exchange or balance adjustment.
        // Any financial history requires a separate, append-only migration design.
        DB::transaction(function (): void {
            $historyTables = [
                'ledger_entries', 'ledger_postings', 'wallet_topup_orders',
                'payment_provider_transactions', 'payment_provider_events',
                'withdrawal_orders', 'withdrawal_destinations', 'withdrawal_transaction_attempts',
                'card_issue_orders', 'card_management_orders', 'user_cards', 'card_transactions',
                'security_deposit_refund_requests', 'initial_deposit_intents',
                'wallet_transfers', 'promotion_funding_events', 'commission_awards', 'commission_transfers',
            ];
            $tables = array_merge($historyTables, ['tenants', 'tenant_business_settings', 'wallets', 'ledger_accounts']);
            sort($tables);
            // Maintenance migration: prevent a payment/hold arriving between preflight and update.
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('LOCK TABLE '.implode(', ', $tables).' IN ACCESS EXCLUSIVE MODE');
            $ids = DB::table('tenants')->where('default_asset', 'USD')->orderBy('id')->pluck('id')->all();
            if ($ids === []) {
                return;
            }
            foreach ($historyTables as $table) {
                if (DB::table($table)->whereIn('tenant_id', $ids)->exists()) {
                    throw new RuntimeException("USDT normalization stopped: legacy USD companies have {$table} history. No data changed; an append-only migration is required.");
                }
            }
            if (DB::table('ledger_accounts')->whereIn('tenant_id', $ids)->where('balance', '<>', '0')->exists()
                || DB::table('ledger_accounts')->whereIn('tenant_id', $ids)->where('asset_code', '<>', 'USD')->exists()
                || DB::table('wallets')->whereIn('tenant_id', $ids)->where('asset_code', '<>', 'USD')->exists()
                || DB::table('tenant_business_settings')->whereIn('tenant_id', $ids)->where('required_security_deposit_asset', '<>', 'USD')->exists()) {
                throw new RuntimeException('USDT normalization stopped: nonzero or mixed-asset legacy accounts. No data changed.');
            }

            // These exceptions exist only inside this locked migration transaction.
            // The ordinary application/database guards are restored before commit.
            DB::statement('ALTER TABLE wallets DISABLE TRIGGER wallets_identity_immutable');
            DB::statement('ALTER TABLE ledger_accounts DISABLE TRIGGER ledger_accounts_identity_immutable');
            DB::statement('ALTER TABLE tenants DISABLE TRIGGER tenant_default_asset_stability');
            DB::statement('ALTER TABLE ledger_accounts ALTER CONSTRAINT ledger_account_wallet_owner_fk DEFERRABLE INITIALLY DEFERRED');

            DB::table('wallets')->whereIn('tenant_id', $ids)->update(['asset_code' => 'USDT']);
            DB::table('ledger_accounts')->whereIn('tenant_id', $ids)->update(['asset_code' => 'USDT']);
            DB::table('tenants')->whereIn('id', $ids)->update(['default_asset' => 'USDT']);
            DB::table('tenant_business_settings')->whereIn('tenant_id', $ids)->update(['required_security_deposit_asset' => 'USDT']);

            DB::statement('SET CONSTRAINTS ledger_account_wallet_owner_fk, tenant_deposit_asset_alignment IMMEDIATE');
            DB::statement('ALTER TABLE ledger_accounts ALTER CONSTRAINT ledger_account_wallet_owner_fk NOT DEFERRABLE');
            DB::statement('ALTER TABLE wallets ENABLE TRIGGER wallets_identity_immutable');
            DB::statement('ALTER TABLE ledger_accounts ENABLE TRIGGER ledger_accounts_identity_immutable');
            DB::statement('ALTER TABLE tenants ENABLE TRIGGER tenant_default_asset_stability');
            DB::statement('SET CONSTRAINTS tenant_deposit_asset_alignment DEFERRED');

            foreach ($ids as $id) {
                DB::table('audit_logs')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $id,
                    'actor_type' => 'SYSTEM', 'actor_id' => null,
                    'action' => 'UNUSED_WALLET_DENOMINATION_NORMALIZED',
                    'resource_type' => 'tenant', 'resource_id' => $id,
                    'before_data' => json_encode(['asset' => 'USD'], JSON_THROW_ON_ERROR),
                    'after_data' => json_encode(['asset' => 'USDT', 'ratio' => '1:1', 'financial_history' => false], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Irreversible: these wallets may now own real USDT postings/orders.
    }
};
