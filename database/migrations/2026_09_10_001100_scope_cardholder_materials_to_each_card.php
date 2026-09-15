<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_cardholders', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'user_id', 'provider']);
            $table->uuid('request_id')->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->uuid('card_product_id')->nullable()->references('id')->on('card_products')->restrictOnDelete();
            $table->text('materials_encrypted')->nullable();
            $table->unsignedInteger('submission_version')->default(0);
            $table->unique(['tenant_id', 'user_id', 'request_id'], 'cardholder_application_request_unique');
            $table->unique(['id', 'tenant_id', 'user_id', 'card_product_id', 'request_id'], 'cardholder_application_scope_unique');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE provider_cardholders ADD CONSTRAINT cardholder_application_materials_check CHECK (
                (request_id IS NULL AND request_hash IS NULL AND card_product_id IS NULL AND materials_encrypted IS NULL AND submission_version = 0)
                OR (request_id IS NOT NULL AND request_hash ~ '^[a-f0-9]{64}$' AND request_hash IS NOT NULL
                    AND card_product_id IS NOT NULL AND materials_encrypted IS NOT NULL AND submission_version > 0)
            )
            SQL);
        Schema::table('card_issue_orders', function (Blueprint $table): void {
            $table->uuid('cardholder_request_id')->nullable();
            $table->foreign(['provider_cardholder_id', 'tenant_id', 'user_id', 'card_product_id', 'cardholder_request_id'], 'card_issue_application_fk')
                ->references(['id', 'tenant_id', 'user_id', 'card_product_id', 'request_id'])->on('provider_cardholders')->restrictOnDelete();
        });
        // Legacy orders may share a holder. They remain unchanged; every NEW application is single-use.
        DB::statement('CREATE UNIQUE INDEX card_issue_application_once ON card_issue_orders (provider_cardholder_id) WHERE cardholder_request_id IS NOT NULL');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_card_issue_application() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' AND NEW.cardholder_request_id IS NULL THEN
                    RAISE EXCEPTION 'New card issues require independent cardholder materials' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE' AND NEW.cardholder_request_id IS DISTINCT FROM OLD.cardholder_request_id THEN
                    RAISE EXCEPTION 'Card application identity is immutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER card_issue_application_guard BEFORE INSERT OR UPDATE ON card_issue_orders
                FOR EACH ROW EXECUTE FUNCTION guard_card_issue_application();

            CREATE OR REPLACE FUNCTION guard_cardholder_application() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.tenant_id <> OLD.tenant_id OR NEW.user_id <> OLD.user_id OR NEW.provider <> OLD.provider
                    OR NEW.request_id IS DISTINCT FROM OLD.request_id OR NEW.card_product_id IS DISTINCT FROM OLD.card_product_id THEN
                    RAISE EXCEPTION 'Cardholder application ownership is immutable' USING ERRCODE = '23514';
                END IF;
                IF NEW.materials_encrypted IS DISTINCT FROM OLD.materials_encrypted
                    OR NEW.request_hash IS DISTINCT FROM OLD.request_hash OR NEW.submission_version <> OLD.submission_version THEN
                    IF OLD.request_id IS NULL OR OLD.status <> 'ACTION_REQUIRED' OR NEW.status <> 'SUBMITTING'
                        OR NEW.submission_version <> OLD.submission_version + 1
                        OR EXISTS (SELECT 1 FROM card_issue_orders WHERE provider_cardholder_id = OLD.id) THEN
                        RAISE EXCEPTION 'Submitted cardholder materials cannot be changed' USING ERRCODE = '23514';
                    END IF;
                END IF;
                IF OLD.provider_cardholder_id IS NOT NULL AND NEW.provider_cardholder_id IS DISTINCT FROM OLD.provider_cardholder_id THEN
                    RAISE EXCEPTION 'External cardholder identity is immutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER cardholder_application_guard BEFORE UPDATE ON provider_cardholders
                FOR EACH ROW EXECUTE FUNCTION guard_cardholder_application();
            SQL);
    }

    public function down(): void
    {
        // A rollback cannot collapse multiple people's materials back into one account-level identity.
        throw new RuntimeException('Per-card material migration requires a forward-only recovery plan.');
    }
};
