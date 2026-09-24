<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
LOCK TABLE card_products IN SHARE ROW EXCLUSIVE MODE;
DROP TRIGGER immutable_bin_claim ON card_bin_claims;
DROP TRIGGER card_bin_claim ON card_products;
DELETE FROM card_bin_claims;
INSERT INTO card_bin_claims(bin,card_provider_reference_id,card_product_id,conflicted)
SELECT provider_product_ref,
 CASE WHEN count(DISTINCT COALESCE(card_provider_reference_id::text,provider))=1 THEN (array_agg(card_provider_reference_id ORDER BY id))[1] ELSE NULL END,
 CASE WHEN count(*)=1 THEN (array_agg(id))[1] ELSE NULL END, count(*)>1
FROM card_products WHERE archived_at IS NULL AND provider_product_ref<>'' GROUP BY provider_product_ref;
CREATE OR REPLACE FUNCTION claim_card_bin() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE old_bin text; new_bin text; lock_bin text;
BEGIN
 IF TG_OP <> 'INSERT' AND OLD.archived_at IS NULL THEN old_bin := OLD.provider_product_ref; END IF;
 IF TG_OP <> 'DELETE' AND NEW.archived_at IS NULL THEN new_bin := NEW.provider_product_ref; END IF;
 IF TG_OP='UPDATE' AND old_bin IS NOT DISTINCT FROM new_bin AND NEW.card_provider_reference_id IS NOT DISTINCT FROM OLD.card_provider_reference_id THEN RETURN NEW; END IF;
 FOR lock_bin IN SELECT DISTINCT b FROM unnest(ARRAY[old_bin,new_bin]) b WHERE b IS NOT NULL AND b<>'' ORDER BY b LOOP
  PERFORM pg_advisory_xact_lock(hashtextextended('card-bin:'||lock_bin,0));
 END LOOP;
 IF old_bin IS NOT NULL AND old_bin<>'' THEN
  DELETE FROM card_bin_claims WHERE bin=old_bin;
  INSERT INTO card_bin_claims(bin,card_provider_reference_id,card_product_id,conflicted)
  SELECT provider_product_ref,
   CASE WHEN count(DISTINCT COALESCE(card_provider_reference_id::text,provider))=1 THEN (array_agg(card_provider_reference_id ORDER BY id))[1] ELSE NULL END,
   CASE WHEN count(*)=1 THEN (array_agg(id))[1] ELSE NULL END, count(*)>1
  FROM card_products WHERE archived_at IS NULL AND provider_product_ref=old_bin AND id<>OLD.id GROUP BY provider_product_ref;
 END IF;
 IF new_bin IS NOT NULL AND new_bin<>'' THEN
  INSERT INTO card_bin_claims(bin,card_provider_reference_id,card_product_id) VALUES(new_bin,NEW.card_provider_reference_id,NEW.id);
 END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION immutable_card_bin_claim() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF pg_trigger_depth()<2 THEN RAISE EXCEPTION 'BIN claims are maintained by product lifecycle'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER immutable_bin_claim BEFORE UPDATE OR DELETE ON card_bin_claims FOR EACH ROW EXECUTE FUNCTION immutable_card_bin_claim();
CREATE TRIGGER card_bin_claim AFTER INSERT OR DELETE OR UPDATE OF provider_product_ref,card_provider_reference_id,archived_at ON card_products FOR EACH ROW EXECUTE FUNCTION claim_card_bin();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('BIN allocation changes require a forward migration.');
    }
};
