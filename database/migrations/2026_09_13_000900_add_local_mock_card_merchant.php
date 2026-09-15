<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE platform_card_provider_references ADD COLUMN runtime_driver varchar(20) NOT NULL DEFAULT 'UNCONFIGURED' CHECK (runtime_driver IN ('UNCONFIGURED', 'LOCAL_MOCK'))");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION protect_card_merchant_runtime() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' OR NEW.runtime_driver IS DISTINCT FROM OLD.runtime_driver THEN
                    IF NEW.runtime_driver = 'LOCAL_MOCK' AND current_database() NOT IN ('card_mock', 'card_ui_test') THEN
                        RAISE EXCEPTION 'Local mock merchants require an isolated database';
                    END IF;
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
            CREATE TRIGGER card_merchant_runtime_guard BEFORE INSERT OR UPDATE ON platform_card_provider_references
                FOR EACH ROW EXECUTE FUNCTION protect_card_merchant_runtime();
            SQL);
    }

    public function down(): void
    {
        throw new LogicException('Preserve merchant routing; use a forward migration.');
    }
};
