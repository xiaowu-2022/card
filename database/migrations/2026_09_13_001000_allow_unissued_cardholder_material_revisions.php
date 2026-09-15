<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_cardholder_application() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.tenant_id <> OLD.tenant_id OR NEW.user_id <> OLD.user_id OR NEW.provider <> OLD.provider
                    OR NEW.request_id IS DISTINCT FROM OLD.request_id OR NEW.card_product_id IS DISTINCT FROM OLD.card_product_id THEN
                    RAISE EXCEPTION 'Cardholder application ownership is immutable' USING ERRCODE = '23514';
                END IF;
                IF NEW.materials_encrypted IS DISTINCT FROM OLD.materials_encrypted
                    OR NEW.request_hash IS DISTINCT FROM OLD.request_hash OR NEW.submission_version <> OLD.submission_version THEN
                    IF OLD.request_id IS NULL OR OLD.status NOT IN ('READY', 'ACTION_REQUIRED')
                        OR OLD.provider_cardholder_id IS NULL OR NEW.status <> 'SUBMITTING'
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
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Cardholder material revisions require forward-only recovery.');
    }
};
