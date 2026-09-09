<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->timestampTz('sealed_at')->nullable();
        });
        DB::statement('DROP TRIGGER ledger_entries_immutable ON ledger_entries');

        DB::table('tenant_business_settings')->update([
            'required_security_deposit_asset' => DB::raw('(SELECT default_asset FROM tenants WHERE tenants.id = tenant_business_settings.tenant_id)'),
        ]);
        DB::table('ledger_entries')->whereNull('sealed_at')->update(['sealed_at' => DB::raw('posted_at')]);

        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_no_self_reversal_check CHECK (reversal_of_entry_id IS NULL OR reversal_of_entry_id <> id)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION protect_ledger_entry_mutation_v41() RETURNS trigger AS $$
            BEGIN
                IF OLD.sealed_at IS NULL
                   AND NEW.sealed_at IS NOT NULL
                   AND NEW.id IS NOT DISTINCT FROM OLD.id
                   AND NEW.tenant_id IS NOT DISTINCT FROM OLD.tenant_id
                   AND NEW.asset_code IS NOT DISTINCT FROM OLD.asset_code
                   AND NEW.event_key IS NOT DISTINCT FROM OLD.event_key
                   AND NEW.event_hash IS NOT DISTINCT FROM OLD.event_hash
                   AND NEW.event_type IS NOT DISTINCT FROM OLD.event_type
                   AND NEW.reference_type IS NOT DISTINCT FROM OLD.reference_type
                   AND NEW.reference_id IS NOT DISTINCT FROM OLD.reference_id
                   AND NEW.reversal_of_entry_id IS NOT DISTINCT FROM OLD.reversal_of_entry_id
                   AND NEW.posted_at IS NOT DISTINCT FROM OLD.posted_at
                   AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'sealed ledger entry is immutable' USING ERRCODE = '55000';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER ledger_entries_immutable
            BEFORE UPDATE OR DELETE ON ledger_entries
            FOR EACH ROW EXECUTE FUNCTION protect_ledger_entry_mutation_v41();

            CREATE OR REPLACE FUNCTION reject_late_ledger_posting_v41() RETURNS trigger AS $$
            BEGIN
                PERFORM 1 FROM ledger_entries
                WHERE id = NEW.ledger_entry_id
                  AND tenant_id = NEW.tenant_id
                  AND asset_code = NEW.asset_code
                  AND sealed_at IS NULL
                FOR UPDATE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'postings require an unsealed ledger entry' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER ledger_postings_unsealed_parent
            BEFORE INSERT ON ledger_postings
            FOR EACH ROW EXECUTE FUNCTION reject_late_ledger_posting_v41();

            CREATE OR REPLACE FUNCTION validate_balanced_ledger_entry() RETURNS trigger AS $$
            DECLARE
                target_entry uuid;
                posting_count bigint;
                posting_total numeric;
                entry_sealed_at timestamptz;
            BEGIN
                IF TG_TABLE_NAME = 'ledger_entries' THEN
                    target_entry := NEW.id;
                ELSE
                    target_entry := NEW.ledger_entry_id;
                END IF;
                SELECT sealed_at INTO entry_sealed_at FROM ledger_entries WHERE id = target_entry;
                SELECT COUNT(*), COALESCE(SUM(delta), 0) INTO posting_count, posting_total
                FROM ledger_postings WHERE ledger_entry_id = target_entry;
                IF entry_sealed_at IS NULL OR posting_count < 2 OR posting_total <> 0 THEN
                    RAISE EXCEPTION 'ledger entry must be sealed with at least two postings that sum exactly to zero' USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION validate_ledger_account_cache_v41() RETURNS trigger AS $$
            DECLARE
                target_account uuid;
                cached_balance numeric;
                posting_balance numeric;
            BEGIN
                IF TG_TABLE_NAME = 'ledger_accounts' THEN
                    target_account := NEW.id;
                ELSE
                    target_account := NEW.ledger_account_id;
                END IF;
                SELECT balance INTO cached_balance FROM ledger_accounts WHERE id = target_account;
                SELECT COALESCE(SUM(p.delta), 0) INTO posting_balance
                FROM ledger_postings p
                JOIN ledger_entries e ON e.id = p.ledger_entry_id
                WHERE p.ledger_account_id = target_account AND e.sealed_at IS NOT NULL;
                IF cached_balance IS NULL OR cached_balance <> posting_balance THEN
                    RAISE EXCEPTION 'ledger account cache does not match sealed posting truth' USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER ledger_account_cache_from_account
            AFTER INSERT OR UPDATE OF balance ON ledger_accounts DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION validate_ledger_account_cache_v41();

            CREATE CONSTRAINT TRIGGER ledger_account_cache_from_posting
            AFTER INSERT ON ledger_postings DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION validate_ledger_account_cache_v41();

            CREATE OR REPLACE FUNCTION protect_ledger_account_identity_v41() RETURNS trigger AS $$
            BEGIN
                IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                   OR NEW.wallet_id IS DISTINCT FROM OLD.wallet_id
                   OR NEW.user_id IS DISTINCT FROM OLD.user_id
                   OR NEW.account_type IS DISTINCT FROM OLD.account_type
                   OR NEW.asset_code IS DISTINCT FROM OLD.asset_code THEN
                    RAISE EXCEPTION 'ledger account identity is immutable' USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER ledger_accounts_identity_immutable
            BEFORE UPDATE ON ledger_accounts
            FOR EACH ROW EXECUTE FUNCTION protect_ledger_account_identity_v41();

            CREATE OR REPLACE FUNCTION protect_wallet_identity_v41() RETURNS trigger AS $$
            BEGIN
                IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                   OR NEW.user_id IS DISTINCT FROM OLD.user_id
                   OR NEW.asset_code IS DISTINCT FROM OLD.asset_code THEN
                    RAISE EXCEPTION 'wallet identity is immutable' USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER wallets_identity_immutable
            BEFORE UPDATE ON wallets
            FOR EACH ROW EXECUTE FUNCTION protect_wallet_identity_v41();

            CREATE OR REPLACE FUNCTION validate_tenant_asset_alignment_v41() RETURNS trigger AS $$
            DECLARE
                configured_asset varchar;
                tenant_asset varchar;
            BEGIN
                IF TG_TABLE_NAME = 'tenants' THEN
                    tenant_asset := NEW.default_asset;
                    SELECT required_security_deposit_asset INTO configured_asset
                    FROM tenant_business_settings WHERE tenant_id = NEW.id;
                    IF OLD.default_asset IS DISTINCT FROM NEW.default_asset
                       AND (EXISTS (SELECT 1 FROM wallets WHERE tenant_id = NEW.id)
                            OR EXISTS (SELECT 1 FROM ledger_accounts WHERE tenant_id = NEW.id)) THEN
                        RAISE EXCEPTION 'tenant default asset is frozen after financial account creation' USING ERRCODE = '23514';
                    END IF;
                ELSE
                    configured_asset := NEW.required_security_deposit_asset;
                    SELECT default_asset INTO tenant_asset FROM tenants WHERE id = NEW.tenant_id;
                END IF;
                IF configured_asset IS NOT NULL AND configured_asset <> tenant_asset THEN
                    RAISE EXCEPTION 'security deposit asset must match tenant default asset' USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER tenant_default_asset_stability
            AFTER UPDATE OF default_asset ON tenants DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION validate_tenant_asset_alignment_v41();

            CREATE CONSTRAINT TRIGGER tenant_deposit_asset_alignment
            AFTER INSERT OR UPDATE OF required_security_deposit_asset ON tenant_business_settings DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION validate_tenant_asset_alignment_v41();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS tenant_deposit_asset_alignment ON tenant_business_settings;
            DROP TRIGGER IF EXISTS tenant_default_asset_stability ON tenants;
            DROP FUNCTION IF EXISTS validate_tenant_asset_alignment_v41();
            DROP TRIGGER IF EXISTS wallets_identity_immutable ON wallets;
            DROP FUNCTION IF EXISTS protect_wallet_identity_v41();
            DROP TRIGGER IF EXISTS ledger_accounts_identity_immutable ON ledger_accounts;
            DROP FUNCTION IF EXISTS protect_ledger_account_identity_v41();
            DROP TRIGGER IF EXISTS ledger_account_cache_from_posting ON ledger_postings;
            DROP TRIGGER IF EXISTS ledger_account_cache_from_account ON ledger_accounts;
            DROP FUNCTION IF EXISTS validate_ledger_account_cache_v41();
            DROP TRIGGER IF EXISTS ledger_postings_unsealed_parent ON ledger_postings;
            DROP FUNCTION IF EXISTS reject_late_ledger_posting_v41();
            DROP TRIGGER IF EXISTS ledger_entries_immutable ON ledger_entries;
            DROP FUNCTION IF EXISTS protect_ledger_entry_mutation_v41();

            CREATE TRIGGER ledger_entries_immutable
            BEFORE UPDATE OR DELETE ON ledger_entries
            FOR EACH ROW EXECUTE FUNCTION reject_ledger_history_mutation();

            CREATE OR REPLACE FUNCTION validate_balanced_ledger_entry() RETURNS trigger AS $$
            DECLARE
                target_entry uuid;
                posting_count bigint;
                posting_total numeric(20,8);
            BEGIN
                IF TG_TABLE_NAME = 'ledger_entries' THEN
                    target_entry := NEW.id;
                ELSE
                    target_entry := NEW.ledger_entry_id;
                END IF;
                SELECT COUNT(*), COALESCE(SUM(delta), 0) INTO posting_count, posting_total
                FROM ledger_postings WHERE ledger_entry_id = target_entry;
                IF posting_count < 2 OR posting_total <> 0 THEN
                    RAISE EXCEPTION 'ledger entry must contain at least two postings that sum exactly to zero' USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_no_self_reversal_check');
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dropColumn('sealed_at');
        });
    }
};
