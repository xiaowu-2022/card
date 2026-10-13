<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_products', fn (Blueprint $table) => $table->string('source_bin', 10)->nullable());
        Schema::table('u1_operations', fn (Blueprint $table) => $table->text('api_key_snapshot_encrypted')->nullable());
        DB::unprepared(<<<'SQL'
ALTER TABLE card_products ADD CONSTRAINT card_products_source_bin_check CHECK (source_bin IS NULL OR source_bin ~ '^[0-9]{6,10}$');
CREATE FUNCTION guard_card_source_bin() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.source_bin IS DISTINCT FROM OLD.source_bin AND (
  EXISTS(SELECT 1 FROM provider_cardholders WHERE card_product_id=OLD.id) OR
  EXISTS(SELECT 1 FROM card_issue_orders WHERE card_product_id=OLD.id) OR
  EXISTS(SELECT 1 FROM user_cards WHERE card_product_id=OLD.id) OR
  EXISTS(SELECT 1 FROM card_recipient_applications WHERE card_product_id=OLD.id)
 ) THEN RAISE EXCEPTION 'Card product source BIN with history is immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER card_source_bin_guard BEFORE UPDATE OF source_bin ON card_products FOR EACH ROW EXECUTE FUNCTION guard_card_source_bin();
CREATE OR REPLACE FUNCTION claim_card_bin() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE old_bin text; new_bin text; lock_bin text;
BEGIN
 IF TG_NAME='card_source_bin_claim' AND (NEW.provider_product_ref IS DISTINCT FROM OLD.provider_product_ref OR NEW.card_provider_reference_id IS DISTINCT FROM OLD.card_provider_reference_id OR NEW.archived_at IS DISTINCT FROM OLD.archived_at) THEN RETURN NEW; END IF;
 IF TG_OP <> 'INSERT' AND OLD.archived_at IS NULL THEN old_bin := COALESCE(OLD.source_bin,OLD.provider_product_ref); END IF;
 IF TG_OP <> 'DELETE' AND NEW.archived_at IS NULL THEN new_bin := COALESCE(NEW.source_bin,NEW.provider_product_ref); END IF;
 IF TG_OP='UPDATE' AND old_bin IS NOT DISTINCT FROM new_bin AND NEW.card_provider_reference_id IS NOT DISTINCT FROM OLD.card_provider_reference_id THEN RETURN NEW; END IF;
 FOR lock_bin IN SELECT DISTINCT b FROM unnest(ARRAY[old_bin,new_bin]) b WHERE b IS NOT NULL AND b<>'' ORDER BY b LOOP
  PERFORM pg_advisory_xact_lock(hashtextextended('card-bin:'||lock_bin,0));
 END LOOP;
 IF old_bin IS NOT NULL AND old_bin<>'' THEN
  DELETE FROM card_bin_claims WHERE bin=old_bin;
  INSERT INTO card_bin_claims(bin,card_provider_reference_id,card_product_id,conflicted)
  SELECT COALESCE(source_bin,provider_product_ref),
   CASE WHEN count(DISTINCT COALESCE(card_provider_reference_id::text,provider))=1 THEN (array_agg(card_provider_reference_id ORDER BY id))[1] ELSE NULL END,
   CASE WHEN count(*)=1 THEN (array_agg(id))[1] ELSE NULL END, count(*)>1
  FROM card_products WHERE archived_at IS NULL AND COALESCE(source_bin,provider_product_ref)=old_bin AND id<>OLD.id GROUP BY COALESCE(source_bin,provider_product_ref);
 END IF;
 IF new_bin IS NOT NULL AND new_bin<>'' THEN
  INSERT INTO card_bin_claims(bin,card_provider_reference_id,card_product_id) VALUES(new_bin,NEW.card_provider_reference_id,NEW.id);
 END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER card_source_bin_claim AFTER UPDATE OF source_bin ON card_products FOR EACH ROW EXECUTE FUNCTION claim_card_bin();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('U1 resource evidence requires a forward migration.');
    }
};
