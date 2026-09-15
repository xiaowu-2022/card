<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // User-approved deployment portability only; preserve identity/ownership immutability.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION protect_card_merchant_runtime() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
            IF TG_OP = 'INSERT' OR NEW.runtime_driver IS DISTINCT FROM OLD.runtime_driver THEN
            IF TG_OP = 'UPDATE' THEN
            IF OLD.runtime_driver <> 'UNCONFIGURED' OR EXISTS (
            SELECT 1 FROM card_products p WHERE p.card_provider_reference_id = OLD.id AND (
            EXISTS (SELECT 1 FROM provider_cardholders h WHERE h.card_product_id = p.id)
            OR EXISTS (SELECT 1 FROM card_issue_orders o WHERE o.card_product_id = p.id)
            OR EXISTS (SELECT 1 FROM user_cards c WHERE c.card_product_id = p.id)
            )
            ) THEN
            RAISE EXCEPTION 'Historical card merchant routing is immutable';
            END IF;
            END IF;
            END IF;
            RETURN NEW;
            END;
            $$;
            CREATE OR REPLACE FUNCTION guard_sandbox_issuing_connection() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
            IF NEW.photonpay_issuing_encrypted IS NOT NULL AND NEW.runtime_driver <> 'UNCONFIGURED' THEN
            RAISE EXCEPTION 'Sandbox issuing requires its original unconfigured merchant';
            END IF;
            IF TG_OP = 'UPDATE' AND OLD.photonpay_issuing_encrypted IS NOT NULL AND NEW.photonpay_issuing_encrypted IS DISTINCT FROM OLD.photonpay_issuing_encrypted THEN
            RAISE EXCEPTION 'Sandbox issuing connection identity is immutable';
            END IF;
            IF TG_OP = 'UPDATE' AND OLD.photonpay_issuing_encrypted IS NULL AND NEW.photonpay_issuing_encrypted IS NOT NULL AND EXISTS (
            SELECT 1 FROM provider_cardholders h JOIN card_products p ON p.id=h.card_product_id WHERE p.card_provider_reference_id=OLD.id
            ) THEN RAISE EXCEPTION 'Cannot reroute historical holders'; END IF;
            RETURN NEW;
            END $$;
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
            IF NEW.provider_cardholder_id <> 'CH2096144404451819520'
            OR NOT EXISTS (SELECT 1 FROM users u JOIN tenants t ON t.id=u.tenant_id WHERE u.id=NEW.user_id AND u.tenant_id=NEW.tenant_id AND u.account_id='202609131303' AND t.name='Tenant A')
            OR NOT EXISTS (SELECT 1 FROM card_products p JOIN platform_card_provider_references r ON r.id=p.card_provider_reference_id WHERE p.id=NEW.card_product_id AND r.photonpay_issuing_encrypted IS NOT NULL)
            THEN RAISE EXCEPTION 'Sandbox holder reuse is outside the approved scope'; END IF;
            END IF;
            RETURN NEW;
            END $$;
            SQL);
    }

    public function down(): void
    {
        throw new LogicException('Preserve deployed merchant identities; use a forward migration.');
    }
};
