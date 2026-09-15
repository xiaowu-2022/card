<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE platform_card_provider_references ADD COLUMN photonpay_issuing_encrypted text;
            CREATE OR REPLACE FUNCTION guard_sandbox_issuing_connection() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.photonpay_issuing_encrypted IS NOT NULL AND (current_database() NOT IN ('card_mock','card_ui_test') OR NEW.runtime_driver <> 'UNCONFIGURED') THEN
                    RAISE EXCEPTION 'Sandbox issuing requires an isolated unconfigured merchant';
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.photonpay_issuing_encrypted IS NOT NULL AND NEW.photonpay_issuing_encrypted IS DISTINCT FROM OLD.photonpay_issuing_encrypted THEN
                    RAISE EXCEPTION 'Sandbox issuing connection identity is immutable';
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.photonpay_issuing_encrypted IS NULL AND NEW.photonpay_issuing_encrypted IS NOT NULL AND EXISTS (
                    SELECT 1 FROM provider_cardholders h JOIN card_products p ON p.id=h.card_product_id WHERE p.card_provider_reference_id=OLD.id
                ) THEN RAISE EXCEPTION 'Cannot reroute historical holders'; END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER sandbox_issuing_connection_guard BEFORE INSERT OR UPDATE ON platform_card_provider_references FOR EACH ROW EXECUTE FUNCTION guard_sandbox_issuing_connection();
            ALTER TABLE provider_cardholders ADD COLUMN sandbox_existing_holder boolean NOT NULL DEFAULT false;
            ALTER TABLE provider_cardholders DROP CONSTRAINT cardholder_application_materials_check;
            ALTER TABLE provider_cardholders ADD CONSTRAINT cardholder_application_materials_check CHECK (
                (NOT sandbox_existing_holder AND request_id IS NULL AND request_hash IS NULL AND card_product_id IS NULL AND materials_encrypted IS NULL AND submission_version=0)
                OR (request_id IS NOT NULL AND request_hash IS NOT NULL AND request_hash ~ '^[a-f0-9]{64}$' AND card_product_id IS NOT NULL AND submission_version>0
                    AND ((NOT sandbox_existing_holder AND materials_encrypted IS NOT NULL) OR (sandbox_existing_holder AND materials_encrypted IS NULL)))
            );
            DROP INDEX provider_cardholder_external_unique;
            CREATE UNIQUE INDEX provider_cardholder_external_unique ON provider_cardholders(provider,provider_cardholder_id) WHERE provider_cardholder_id IS NOT NULL AND NOT sandbox_existing_holder;
            CREATE UNIQUE INDEX sandbox_holder_product_once ON provider_cardholders(provider,provider_cardholder_id,card_product_id) WHERE sandbox_existing_holder;
            CREATE OR REPLACE FUNCTION guard_sandbox_existing_holder() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='UPDATE' AND NEW.sandbox_existing_holder IS DISTINCT FROM OLD.sandbox_existing_holder THEN RAISE EXCEPTION 'Holder origin is immutable'; END IF;
                IF NEW.provider_cardholder_id IS NOT NULL THEN
                    PERFORM pg_advisory_xact_lock(hashtextextended(NEW.provider || ':' || NEW.provider_cardholder_id, 0));
                    IF EXISTS (SELECT 1 FROM provider_cardholders h WHERE h.id<>NEW.id AND h.provider=NEW.provider AND h.provider_cardholder_id=NEW.provider_cardholder_id
                        AND (NOT h.sandbox_existing_holder OR NOT NEW.sandbox_existing_holder OR h.tenant_id<>NEW.tenant_id OR h.user_id<>NEW.user_id)) THEN
                        RAISE EXCEPTION 'External holder ownership conflict';
                    END IF;
                END IF;
                IF NEW.sandbox_existing_holder THEN
                    IF current_database() NOT IN ('card_mock','card_ui_test') OR NEW.provider_cardholder_id <> 'CH2096144404451819520'
                        OR NOT EXISTS (SELECT 1 FROM users u JOIN tenants t ON t.id=u.tenant_id WHERE u.id=NEW.user_id AND u.tenant_id=NEW.tenant_id AND u.account_id='202609131303' AND t.name='Tenant A')
                        OR NOT EXISTS (SELECT 1 FROM card_products p JOIN platform_card_provider_references r ON r.id=p.card_provider_reference_id WHERE p.id=NEW.card_product_id AND r.photonpay_issuing_encrypted IS NOT NULL)
                    THEN RAISE EXCEPTION 'Sandbox holder reuse is outside the approved scope'; END IF;
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER sandbox_existing_holder_guard BEFORE INSERT OR UPDATE ON provider_cardholders FOR EACH ROW EXECUTE FUNCTION guard_sandbox_existing_holder();
        SQL);
    }

    public function down(): void
    {
        throw new LogicException('Sandbox issuing identities require forward recovery.');
    }
};
