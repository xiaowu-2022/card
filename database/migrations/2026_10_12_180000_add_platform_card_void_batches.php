<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_void_batches', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('actor_id')->constrained('admin_users')->restrictOnDelete();
            $t->uuid('request_id');
            $t->string('request_hash', 64);
            $t->foreignUuid('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $t->timestampsTz();
            $t->unique(['actor_id', 'request_id']);
        });
        Schema::create('card_void_items', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('batch_id')->constrained('card_void_batches')->restrictOnDelete();
            $t->uuid('tenant_id');
            $t->uuid('user_id');
            $t->uuid('card_id');
            $t->foreign(['card_id', 'tenant_id', 'user_id'])->references(['id', 'tenant_id', 'user_id'])->on('user_cards')->restrictOnDelete();
            $t->string('binding_hash', 64);
            $t->decimal('preview_balance', 20, 8)->nullable();
            $t->string('status', 16)->default('PENDING');
            $t->string('stage', 16)->default('CHECK');
            $t->unsignedInteger('attempt')->default(1);
            $t->decimal('return_amount', 20, 8)->nullable();
            $t->string('error_code', 64)->nullable();
            $t->uuid('lease_token')->nullable();
            $t->timestampTz('lease_until')->nullable();
            $t->timestampTz('next_attempt_at');
            $t->timestampsTz();
            $t->unique(['batch_id', 'card_id']);
        });
        DB::statement("CREATE UNIQUE INDEX card_void_active_card ON card_void_items(card_id) WHERE status IN ('PENDING','RUNNING','UNKNOWN')");
        DB::statement("ALTER TABLE card_void_items ADD CONSTRAINT card_void_values CHECK (status IN ('PENDING','RUNNING','UNKNOWN','SUCCEEDED','FAILED') AND stage IN ('CHECK','FREEZE','RETURN','OVERFLOW','CANCEL','VERIFY','DONE') AND attempt>0 AND (return_amount IS NULL OR return_amount>=0) AND ((lease_token IS NULL)=(lease_until IS NULL)))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_card_void_identity() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Card void history cannot be deleted'; END IF;
 IF TG_TABLE_NAME='card_void_batches' THEN
  IF (NEW.id,NEW.actor_id,NEW.request_id,NEW.request_hash,NEW.tenant_id,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.actor_id,OLD.request_id,OLD.request_hash,OLD.tenant_id,OLD.created_at) THEN RAISE EXCEPTION 'Card void batch identity is immutable'; END IF;
 ELSE
  IF (NEW.id,NEW.batch_id,NEW.tenant_id,NEW.user_id,NEW.card_id,NEW.binding_hash,NEW.preview_balance,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.batch_id,OLD.tenant_id,OLD.user_id,OLD.card_id,OLD.binding_hash,OLD.preview_balance,OLD.created_at) OR (OLD.status='SUCCEEDED' AND NEW IS DISTINCT FROM OLD) THEN RAISE EXCEPTION 'Card void item identity/result is immutable'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER card_void_batch_identity BEFORE UPDATE OR DELETE ON card_void_batches FOR EACH ROW EXECUTE FUNCTION protect_card_void_identity();
CREATE TRIGGER card_void_item_identity BEFORE UPDATE OR DELETE ON card_void_items FOR EACH ROW EXECUTE FUNCTION protect_card_void_identity();
SQL);
        Schema::table('card_overflow_movements', fn (Blueprint $t) => $t->foreignUuid('void_item_id')->nullable()->constrained('card_void_items')->restrictOnDelete());
        $constraint = DB::selectOne("SELECT conname FROM pg_constraint WHERE conrelid='card_overflow_movements'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%FUNDING%'");
        DB::statement('ALTER TABLE card_overflow_movements DROP CONSTRAINT "'.$constraint->conname.'"');
        DB::statement("ALTER TABLE card_overflow_movements ADD CONSTRAINT card_overflow_kind_check CHECK (amount>0 AND ((kind='FUNDING' AND order_id IS NOT NULL AND actor_id IS NULL AND void_item_id IS NULL) OR (kind='SPEND' AND order_id IS NULL AND actor_id IS NOT NULL AND void_item_id IS NULL) OR (kind='RETURN' AND order_id IS NULL AND actor_id IS NOT NULL AND void_item_id IS NOT NULL)))");
        $definition = DB::selectOne("SELECT pg_get_functiondef('check_card_overflow_evidence()'::regprocedure) AS definition")->definition;
        $old = "a.account_type='TENANT_CARD_FUNDING_CLEARING' AND a.card_id IS NULL AND a.user_id IS NULL AND a.wallet_id IS NULL";
        $new = "((m.kind<>'RETURN' AND a.account_type='TENANT_CARD_FUNDING_CLEARING' AND a.card_id IS NULL AND a.user_id IS NULL AND a.wallet_id IS NULL) OR (m.kind='RETURN' AND a.account_type='USER_AVAILABLE' AND a.user_id=m.user_id AND a.wallet_id=(SELECT wallet_id FROM ledger_accounts WHERE card_id=m.card_id AND account_type='USER_CARD_OVERFLOW')))";
        if (! str_contains($definition, $old)) {
            throw new RuntimeException('Overflow evidence definition changed; review migration.');
        }
        $definition = str_replace($old, $new, $definition);
        $guard = "IF m.kind='RETURN' AND NOT EXISTS (SELECT 1 FROM card_void_items i JOIN card_void_batches b ON b.id=i.batch_id WHERE i.id=m.void_item_id AND i.tenant_id=m.tenant_id AND i.user_id=m.user_id AND i.card_id=m.card_id AND b.actor_id=m.actor_id) THEN RAISE EXCEPTION 'Overflow return requires scoped void evidence'; END IF;";
        $definition = str_replace("expected := CASE WHEN m.kind='FUNDING'", $guard."\n expected := CASE WHEN m.kind='FUNDING'", $definition);
        DB::unprepared($definition);
    }

    public function down(): void
    {
        throw new RuntimeException('Card void and refund history cannot be dropped.');
    }
};
