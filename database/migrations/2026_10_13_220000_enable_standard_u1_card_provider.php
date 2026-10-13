<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('u1_test_connections', 'u1_connections');
        Schema::rename('u1_test_requests', 'u1_notification_tests');
        Schema::rename('u1_test_notifications', 'u1_notifications');
        Schema::table('u1_connections', function (Blueprint $table): void {
            $table->boolean('enabled')->default(false);
            $table->boolean('request_ids_confirmed')->default(false);
            $table->boolean('zero_fees_confirmed')->default(false);
            $table->string('check_status', 20)->default('UNCHECKED');
            $table->jsonb('group_catalog')->nullable();
            $table->jsonb('account_report')->nullable();
            $table->timestampTz('checked_at')->nullable();
        });
        Schema::table('u1_notifications', function (Blueprint $table): void {
            $table->string('processing_status', 20)->default('PENDING');
            $table->timestampTz('processed_at')->nullable();
        });
        Schema::create('u1_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('connection_id')->constrained('u1_connections')->restrictOnDelete();
            $table->uuid('request_id');
            $table->char('intent_hash', 64);
            $table->string('kind', 20);
            $table->string('card_id', 180)->nullable();
            $table->string('group_no', 64)->nullable();
            $table->string('currency', 12);
            $table->decimal('amount', 20, 8);
            $table->string('api_id', 180)->nullable();
            $table->string('status', 20);
            $table->boolean('request_ids_confirmed');
            $table->timestampTz('requested_at');
            $table->timestampTz('last_checked_at')->nullable();
            $table->unique(['connection_id', 'request_id']);
            $table->unique(['connection_id', 'api_id']);
        });
        // Broaden only provider discriminators. Keep money, masks and every historical identity guard.
        foreach (['provider_cardholders' => 'provider_cardholders_provider_check', 'card_issue_orders' => 'card_issue_fixed_identity_check', 'user_cards' => 'user_cards_safe_identity_check'] as $table => $constraint) {
            $definition = DB::selectOne('SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid=?::regclass AND conname=?', [$table, $constraint])->definition;
            $updated = preg_replace("/\\(provider\\)::text = 'PHOTONPAY'::text/", "(provider)::text = ANY (ARRAY['PHOTONPAY'::text, 'U1'::text])", $definition, 1, $count);
            if ($count !== 1) {
                throw new RuntimeException('Unexpected provider identity constraint.');
            }
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} {$updated}");
        }
        DB::statement('ALTER TABLE provider_cardholders DROP CONSTRAINT provider_cardholders_status_check');
        DB::statement("ALTER TABLE provider_cardholders ADD CONSTRAINT provider_cardholders_status_check CHECK (status IN ('SUBMITTING','PENDING','READY','LOCAL_READY','ACTION_REQUIRED','REJECTED','DISABLED','UNKNOWN'))");
        DB::statement("ALTER TABLE provider_cardholders ADD CONSTRAINT u1_local_materials_check CHECK (status <> 'LOCAL_READY' OR (provider='U1' AND provider_cardholder_id IS NULL))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_cardholder_application() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.tenant_id <> OLD.tenant_id OR NEW.user_id <> OLD.user_id OR NEW.provider <> OLD.provider
  OR NEW.request_id IS DISTINCT FROM OLD.request_id OR NEW.card_product_id IS DISTINCT FROM OLD.card_product_id THEN
  RAISE EXCEPTION 'Cardholder application ownership is immutable' USING ERRCODE='23514';
 END IF;
 IF NEW.materials_encrypted IS DISTINCT FROM OLD.materials_encrypted OR NEW.request_hash IS DISTINCT FROM OLD.request_hash OR NEW.submission_version <> OLD.submission_version THEN
  IF OLD.request_id IS NULL OR NEW.submission_version <> OLD.submission_version+1
   OR EXISTS(SELECT 1 FROM card_issue_orders WHERE provider_cardholder_id=OLD.id)
   OR NOT ((OLD.status IN ('READY','ACTION_REQUIRED') AND OLD.provider_cardholder_id IS NOT NULL AND NEW.status='SUBMITTING')
    OR (OLD.provider='U1' AND OLD.status='LOCAL_READY' AND OLD.provider_cardholder_id IS NULL AND NEW.status='LOCAL_READY')) THEN
   RAISE EXCEPTION 'Submitted cardholder materials cannot be changed' USING ERRCODE='23514';
  END IF;
 END IF;
 IF OLD.provider_cardholder_id IS NOT NULL AND NEW.provider_cardholder_id IS DISTINCT FROM OLD.provider_cardholder_id THEN
  RAISE EXCEPTION 'External cardholder identity is immutable' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('U1 connection and business history require a forward migration.');
    }
};
