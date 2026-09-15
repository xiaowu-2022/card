<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE card_products ADD COLUMN card_provider_reference_id uuid NULL REFERENCES platform_card_provider_references(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE card_products DROP CONSTRAINT card_product_identity_check');
        DB::statement("ALTER TABLE card_products ADD CONSTRAINT card_product_identity_check CHECK (card_currency = 'USD' AND card_type = 'REGULAR' AND ((provider = 'PHOTONPAY' AND card_provider_reference_id IS NULL) OR provider = 'UNCONFIGURED'))");
        DB::statement('ALTER TABLE card_products DROP CONSTRAINT card_products_provider_provider_product_ref_unique');
        DB::statement("CREATE UNIQUE INDEX card_product_provider_bin_unique ON card_products(provider, COALESCE(card_provider_reference_id, '00000000-0000-0000-0000-000000000000'::uuid), provider_product_ref) WHERE provider_product_ref <> ''");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION protect_card_product_routing() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.provider IS DISTINCT FROM OLD.provider
                    OR NEW.card_provider_reference_id IS DISTINCT FROM OLD.card_provider_reference_id
                    OR NEW.provider_product_ref IS DISTINCT FROM OLD.provider_product_ref
                    OR NEW.card_currency IS DISTINCT FROM OLD.card_currency
                    OR NEW.card_type IS DISTINCT FROM OLD.card_type THEN
                    IF EXISTS (SELECT 1 FROM provider_cardholders WHERE card_product_id = OLD.id)
                        OR EXISTS (SELECT 1 FROM card_issue_orders WHERE card_product_id = OLD.id)
                        OR EXISTS (SELECT 1 FROM user_cards WHERE card_product_id = OLD.id) THEN
                        RAISE EXCEPTION 'Referenced card product routing is immutable';
                    END IF;
                    IF OLD.provider = 'UNCONFIGURED' AND NEW.provider <> 'UNCONFIGURED' THEN
                        RAISE EXCEPTION 'Unconfigured products require a separately implemented provider integration';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER card_product_routing_immutable BEFORE UPDATE ON card_products
                FOR EACH ROW EXECUTE FUNCTION protect_card_product_routing();
            SQL);
    }

    public function down(): void
    {
        throw new LogicException('Preserve product provider bindings; use a forward migration.');
    }
};
