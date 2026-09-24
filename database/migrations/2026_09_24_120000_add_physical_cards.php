<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_card_provider_references', fn (Blueprint $t) => $t->jsonb('bin_catalog')->nullable());
        Schema::table('card_products', function (Blueprint $t): void {
            $t->jsonb('supported_form_factors')->default('["virtual_card"]');
            $t->timestampTz('form_factors_synced_at')->nullable();
        });
        Schema::create('card_recipient_applications', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants');
            $t->uuid('user_id');
            $t->foreignUuid('card_product_id')->constrained('card_products');
            $t->foreignUuid('card_provider_reference_id')->nullable()->constrained('platform_card_provider_references');
            $t->foreignUuid('cardholder_application_id')->constrained('provider_cardholders');
            $t->uuid('request_id');
            $t->string('request_hash', 64);
            $t->text('materials_encrypted');
            $t->string('provider_recipient_id', 180)->nullable();
            $t->string('status', 20);
            $t->timestampsTz();
            $t->unique(['tenant_id', 'user_id', 'request_id']);
            $t->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users');
        });
        Schema::table('provider_cardholders', fn (Blueprint $t) => $t->string('form_factor', 20)->default('virtual_card'));
        Schema::table('card_issue_orders', function (Blueprint $t): void {
            $t->string('form_factor', 20)->default('virtual_card');
            $t->foreignUuid('recipient_application_id')->nullable()->unique()->constrained('card_recipient_applications');
            $t->string('provider_recipient_id', 180)->nullable();
            $t->text('recipient_snapshot_encrypted')->nullable();
        });
        Schema::table('user_cards', function (Blueprint $t): void {
            $t->string('form_factor', 20)->default('virtual_card');
            $t->string('produce_status', 20)->nullable();
            $t->string('tracking_number', 100)->nullable();
        });
        Schema::create('card_activation_attempts', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('card_id')->constrained('user_cards');
            $t->uuid('tenant_id');
            $t->uuid('user_id');
            $t->uuid('request_id');
            $t->string('status', 20);
            $t->timestampsTz();
            $t->unique(['card_id', 'request_id']);
            $t->foreign(['card_id', 'tenant_id', 'user_id'])->references(['id', 'tenant_id', 'user_id'])->on('user_cards');
        });
        DB::unprepared(<<<'SQL'
CREATE UNIQUE INDEX card_activation_outstanding ON card_activation_attempts(card_id) WHERE status IN ('PROCESSING','UNKNOWN','SUCCEEDED');
ALTER TABLE card_activation_attempts ADD CONSTRAINT activation_state CHECK (status IN ('PROCESSING','UNKNOWN','SUCCEEDED','FAILED'));
ALTER TABLE card_recipient_applications ADD CONSTRAINT recipient_state CHECK (status IN ('SUBMITTING','UNKNOWN','READY','FAILED'));
CREATE UNIQUE INDEX card_recipient_outstanding ON card_recipient_applications(cardholder_application_id) WHERE status <> 'FAILED';
CREATE OR REPLACE FUNCTION guard_physical_card_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='UPDATE' AND NEW.form_factor IS DISTINCT FROM OLD.form_factor THEN RAISE EXCEPTION 'Card form factor is immutable'; END IF;
 IF NEW.form_factor NOT IN ('virtual_card','physical_card') THEN RAISE EXCEPTION 'Invalid card form factor'; END IF;
 IF TG_TABLE_NAME='card_issue_orders' THEN
  IF TG_OP='UPDATE' AND (NEW.recipient_application_id,NEW.provider_recipient_id,NEW.recipient_snapshot_encrypted) IS DISTINCT FROM (OLD.recipient_application_id,OLD.provider_recipient_id,OLD.recipient_snapshot_encrypted) THEN RAISE EXCEPTION 'Recipient snapshot is immutable'; END IF;
  IF NOT EXISTS (SELECT 1 FROM provider_cardholders h WHERE h.id=NEW.provider_cardholder_id AND h.form_factor=NEW.form_factor) THEN RAISE EXCEPTION 'Holder form mismatch'; END IF;
  IF NEW.form_factor='physical_card' AND (NEW.recipient_snapshot_encrypted IS NULL OR NOT EXISTS (SELECT 1 FROM card_recipient_applications r WHERE r.id=NEW.recipient_application_id AND r.tenant_id=NEW.tenant_id AND r.user_id=NEW.user_id AND r.card_product_id=NEW.card_product_id AND r.cardholder_application_id=NEW.provider_cardholder_id AND r.provider_recipient_id=NEW.provider_recipient_id AND r.status='READY')) THEN RAISE EXCEPTION 'Physical recipient scope mismatch'; END IF;
  IF NEW.form_factor='virtual_card' AND (NEW.recipient_application_id IS NOT NULL OR NEW.provider_recipient_id IS NOT NULL OR NEW.recipient_snapshot_encrypted IS NOT NULL) THEN RAISE EXCEPTION 'Virtual card recipient forbidden'; END IF;
 END IF;
 IF TG_TABLE_NAME='user_cards' THEN
  IF NOT EXISTS (SELECT 1 FROM card_issue_orders o WHERE o.id=NEW.card_issue_order_id AND o.form_factor=NEW.form_factor) THEN RAISE EXCEPTION 'Card form does not match order'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER physical_holder_identity BEFORE INSERT OR UPDATE ON provider_cardholders FOR EACH ROW EXECUTE FUNCTION guard_physical_card_identity();
CREATE TRIGGER physical_order_identity BEFORE INSERT OR UPDATE ON card_issue_orders FOR EACH ROW EXECUTE FUNCTION guard_physical_card_identity();
CREATE TRIGGER physical_card_identity BEFORE INSERT OR UPDATE ON user_cards FOR EACH ROW EXECUTE FUNCTION guard_physical_card_identity();
CREATE OR REPLACE FUNCTION guard_recipient_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='UPDATE' AND (NEW.tenant_id,NEW.user_id,NEW.card_product_id,NEW.card_provider_reference_id,NEW.cardholder_application_id,NEW.request_id,NEW.request_hash,NEW.materials_encrypted) IS DISTINCT FROM (OLD.tenant_id,OLD.user_id,OLD.card_product_id,OLD.card_provider_reference_id,OLD.cardholder_application_id,OLD.request_id,OLD.request_hash,OLD.materials_encrypted) THEN RAISE EXCEPTION 'Recipient identity is immutable'; END IF;
 IF TG_OP='UPDATE' AND OLD.provider_recipient_id IS NOT NULL AND NEW.provider_recipient_id IS DISTINCT FROM OLD.provider_recipient_id THEN RAISE EXCEPTION 'Recipient external ID is immutable'; END IF;
 IF NOT EXISTS (SELECT 1 FROM provider_cardholders h JOIN card_products p ON p.id=h.card_product_id WHERE h.id=NEW.cardholder_application_id AND h.tenant_id=NEW.tenant_id AND h.user_id=NEW.user_id AND h.card_product_id=NEW.card_product_id AND h.form_factor='physical_card' AND p.card_provider_reference_id IS NOT DISTINCT FROM NEW.card_provider_reference_id) THEN RAISE EXCEPTION 'Recipient owner mismatch'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER recipient_identity BEFORE INSERT OR UPDATE ON card_recipient_applications FOR EACH ROW EXECUTE FUNCTION guard_recipient_identity();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Physical card history requires a forward migration.');
    }
};
