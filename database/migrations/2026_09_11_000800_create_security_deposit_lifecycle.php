<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('initial_deposit_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->uuid('user_id');
            $table->uuid('topup_id');
            $table->string('status', 16)->default('PENDING');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'user_id']);
            $table->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users');
            $table->foreign(['topup_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('wallet_topup_orders');
        });
        Schema::create('security_deposit_refund_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->uuid('user_id');
            $table->uuid('wallet_id');
            $table->uuid('request_id');
            $table->string('asset_code', 12)->default('USDT');
            $table->decimal('amount', 20, 8);
            $table->string('status', 16)->default('CHECKING');
            $table->uuid('ledger_entry_id')->nullable()->unique();
            $table->jsonb('card_checks')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'user_id', 'request_id']);
            $table->foreign(['wallet_id', 'tenant_id', 'user_id', 'asset_code'])->references(['id', 'tenant_id', 'user_id', 'asset_code'])->on('wallets');
            $table->foreign(['ledger_entry_id', 'tenant_id', 'asset_code'])->references(['id', 'tenant_id', 'asset_code'])->on('ledger_entries');
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE initial_deposit_intents ADD CONSTRAINT initial_deposit_status_check CHECK (status IN ('PENDING','DONE'));
            ALTER TABLE security_deposit_refund_requests ADD CONSTRAINT deposit_refund_state_check CHECK
                (asset_code='USDT' AND amount>0 AND status IN ('CHECKING','COMPLETED','CANCELLED') AND
                ((status='COMPLETED' AND ledger_entry_id IS NOT NULL AND completed_at IS NOT NULL AND card_checks IS NOT NULL) OR
                 (status<>'COMPLETED' AND ledger_entry_id IS NULL AND completed_at IS NULL)));
            CREATE UNIQUE INDEX deposit_refund_one_pending ON security_deposit_refund_requests (tenant_id,user_id) WHERE status='CHECKING';
            CREATE OR REPLACE FUNCTION protect_deposit_lifecycle() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Deposit lifecycle history is immutable'; END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id OR NEW.user_id IS DISTINCT FROM OLD.user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'Deposit lifecycle identity is immutable';
                END IF;
                IF TG_TABLE_NAME='initial_deposit_intents' THEN
                    IF NEW.topup_id IS DISTINCT FROM OLD.topup_id OR (OLD.status='DONE' AND NEW.status<>'DONE') THEN RAISE EXCEPTION 'Initial deposit intent cannot be changed'; END IF;
                ELSE
                    IF NEW.wallet_id IS DISTINCT FROM OLD.wallet_id OR NEW.request_id IS DISTINCT FROM OLD.request_id OR NEW.asset_code IS DISTINCT FROM OLD.asset_code OR NEW.amount IS DISTINCT FROM OLD.amount OR OLD.status IN ('COMPLETED','CANCELLED') THEN
                        RAISE EXCEPTION 'Refund identity and terminal state are immutable';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER initial_deposit_immutable BEFORE UPDATE OR DELETE ON initial_deposit_intents FOR EACH ROW EXECUTE FUNCTION protect_deposit_lifecycle();
            CREATE TRIGGER deposit_refund_immutable BEFORE UPDATE OR DELETE ON security_deposit_refund_requests FOR EACH ROW EXECUTE FUNCTION protect_deposit_lifecycle();
            CREATE OR REPLACE FUNCTION validate_deposit_refund_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE r security_deposit_refund_requests%ROWTYPE; e ledger_entries%ROWTYPE; n integer; c integer;
            BEGIN
                SELECT * INTO r FROM security_deposit_refund_requests WHERE tenant_id=NEW.tenant_id AND id=NEW.id;
                IF r.status<>'COMPLETED' THEN RETURN NULL; END IF;
                SELECT * INTO e FROM ledger_entries WHERE tenant_id=r.tenant_id AND id=r.ledger_entry_id;
                SELECT count(*) INTO n FROM ledger_postings p JOIN ledger_accounts a ON a.id=p.ledger_account_id AND a.tenant_id=p.tenant_id
                WHERE p.tenant_id=r.tenant_id AND p.ledger_entry_id=e.id AND a.wallet_id=r.wallet_id AND a.user_id=r.user_id
                AND ((a.account_type='USER_SECURITY_DEPOSIT' AND p.delta=-r.amount) OR (a.account_type='USER_AVAILABLE' AND p.delta=r.amount));
                SELECT count(*) INTO c FROM ledger_postings WHERE tenant_id=r.tenant_id AND ledger_entry_id=e.id;
                IF e.id IS NULL OR e.sealed_at IS NULL OR e.event_key<>'deposit_refund:'||r.id::text OR e.event_type<>'SECURITY_DEPOSIT_REFUND' OR e.reference_id<>r.id OR n<>2 OR c<>2 THEN
                    RAISE EXCEPTION 'Refund requires exact sealed financial evidence';
                END IF;
                RETURN NULL;
            END; $$;
            CREATE CONSTRAINT TRIGGER deposit_refund_evidence AFTER INSERT OR UPDATE ON security_deposit_refund_requests DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_deposit_refund_evidence();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Deposit lifecycle history requires a forward migration.');
    }
};
