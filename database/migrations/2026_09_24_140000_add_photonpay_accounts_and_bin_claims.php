<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_card_provider_references', function (Blueprint $t): void {
            $t->jsonb('photonpay_identity')->nullable();
            $t->text('photonpay_webhook_key_encrypted')->nullable();
            $t->boolean('photonpay_enabled')->default(false);
            $t->timestampTz('photonpay_checked_at')->nullable();
            $t->string('photonpay_check_status', 32)->nullable();
            $t->string('photonpay_migration_error', 80)->nullable();
        });
        // No network or financial writes. Ambiguous legacy configurations remain blocked.
        foreach (DB::table('platform_card_provider_references')->where(fn ($q) => $q->whereNotNull('photonpay_issuing_encrypted')->orWhereNotNull('photonpay_reporting_encrypted'))->get() as $row) {
            try {
                $c = json_decode(Crypt::decryptString($row->photonpay_issuing_encrypted), true, 512, JSON_THROW_ON_ERROR);
                $r = $row->photonpay_reporting_encrypted ? json_decode(Crypt::decryptString($row->photonpay_reporting_encrypted), true, 512, JSON_THROW_ON_ERROR) : $c;
                foreach (['base_url', 'app_id', 'app_secret'] as $field) {
                    if (($r[$field] ?? null) !== ($c[$field] ?? null)) {
                        throw new RuntimeException('connection_mismatch');
                    }
                }
                foreach (['account_id', 'member_id', 'matrix_account'] as $field) {
                    if (isset($r[$field]) && $r[$field] !== ($c[$field] ?? null)) {
                        throw new RuntimeException('connection_identity_mismatch');
                    }
                }
                $identity = array_intersect_key($c, array_flip(['base_url', 'account_id', 'member_id', 'matrix_account']));
                $identity['matrix_account'] ??= null;
                if (empty($identity['member_id']) || empty($identity['account_id'])) {
                    throw new RuntimeException('identity_incomplete');
                }
                $key = $c['base_url'] === config('card-provider.photonpay.base_url') && $c['app_id'] === config('card-provider.photonpay.app_id') && $c['account_id'] === config('card-provider.photonpay.account_id_usd') && $c['member_id'] === config('card-provider.photonpay.member_id') && ($c['matrix_account'] ?? null) === config('card-provider.photonpay.matrix_account') ? config('card-provider.photonpay.webhook_public_key') : null;
                DB::table('platform_card_provider_references')->where('id', $row->id)->update([
                    'photonpay_identity' => json_encode($identity), 'photonpay_enabled' => true,
                    'photonpay_webhook_key_encrypted' => $key ? Crypt::encryptString($key) : null,
                    'photonpay_check_status' => 'UNCHECKED',
                ]);
            } catch (Throwable) {
                DB::table('platform_card_provider_references')->where('id', $row->id)->update(['photonpay_migration_error' => 'LEGACY_CONNECTION_REQUIRES_REVIEW']);
            }
        }
        Schema::create('card_bin_claims', function (Blueprint $t): void {
            $t->string('bin', 64)->primary();
            $t->foreignUuid('card_provider_reference_id')->nullable()->constrained('platform_card_provider_references');
            $t->foreignUuid('card_product_id')->nullable()->constrained('card_products');
            $t->boolean('conflicted')->default(false);
            $t->timestampTz('created_at')->useCurrent();
        });
        DB::statement(<<<'SQL'
INSERT INTO card_bin_claims(bin,card_provider_reference_id,card_product_id,conflicted)
SELECT provider_product_ref,
 CASE WHEN count(DISTINCT COALESCE(card_provider_reference_id::text,provider))=1 THEN (array_agg(card_provider_reference_id ORDER BY (archived_at IS NULL) DESC,created_at,id))[1] ELSE NULL END,
 CASE WHEN count(DISTINCT COALESCE(card_provider_reference_id::text,provider))=1 AND count(*) FILTER(WHERE archived_at IS NULL)<=1 THEN (array_agg(id ORDER BY (archived_at IS NULL) DESC,created_at,id))[1] ELSE NULL END,
 count(DISTINCT COALESCE(card_provider_reference_id::text,provider))>1 OR count(*) FILTER(WHERE archived_at IS NULL)>1
FROM card_products WHERE provider_product_ref<>'' GROUP BY provider_product_ref
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION claim_card_bin() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='UPDATE' THEN
  IF NEW.provider_product_ref=OLD.provider_product_ref AND NEW.card_provider_reference_id IS NOT DISTINCT FROM OLD.card_provider_reference_id THEN RETURN NEW; END IF;
 END IF;
 IF NEW.provider_product_ref IS NULL OR NEW.provider_product_ref='' THEN RETURN NEW; END IF;
 INSERT INTO card_bin_claims(bin,card_provider_reference_id,card_product_id) VALUES(NEW.provider_product_ref,NEW.card_provider_reference_id,NEW.id);
 RETURN NEW;
END $$;
CREATE TRIGGER card_bin_claim AFTER INSERT OR UPDATE OF provider_product_ref,card_provider_reference_id ON card_products FOR EACH ROW EXECUTE FUNCTION claim_card_bin();
CREATE OR REPLACE FUNCTION immutable_card_bin_claim() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'BIN reservations are permanent'; END $$;
CREATE TRIGGER immutable_bin_claim BEFORE UPDATE OR DELETE ON card_bin_claims FOR EACH ROW EXECUTE FUNCTION immutable_card_bin_claim();
CREATE OR REPLACE FUNCTION guard_sandbox_issuing_connection() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.photonpay_issuing_encrypted IS NOT NULL AND NEW.runtime_driver <> 'UNCONFIGURED' THEN RAISE EXCEPTION 'PhotonPay cannot replace a simulated merchant'; END IF;
 IF TG_OP='UPDATE' THEN
  IF OLD.photonpay_identity IS NOT NULL AND NEW.photonpay_identity IS DISTINCT FROM OLD.photonpay_identity AND (EXISTS(SELECT 1 FROM provider_cardholders h JOIN card_products p ON p.id=h.card_product_id WHERE p.card_provider_reference_id=OLD.id) OR EXISTS(SELECT 1 FROM card_recipient_applications r JOIN card_products p ON p.id=r.card_product_id WHERE p.card_provider_reference_id=OLD.id) OR EXISTS(SELECT 1 FROM card_issue_orders o JOIN card_products p ON p.id=o.card_product_id WHERE p.card_provider_reference_id=OLD.id) OR EXISTS(SELECT 1 FROM user_cards c JOIN card_products p ON p.id=c.card_product_id WHERE p.card_provider_reference_id=OLD.id)) THEN RAISE EXCEPTION 'PhotonPay account identity is immutable'; END IF;
  IF OLD.photonpay_issuing_encrypted IS NULL AND NEW.photonpay_issuing_encrypted IS NOT NULL AND EXISTS(SELECT 1 FROM provider_cardholders h JOIN card_products p ON p.id=h.card_product_id WHERE p.card_provider_reference_id=OLD.id) THEN RAISE EXCEPTION 'Cannot reroute historical holders'; END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
        Schema::table('card_provider_events', fn (Blueprint $t) => $t->foreignUuid('card_provider_reference_id')->nullable()->constrained('platform_card_provider_references'));
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_event_account() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.card_provider_reference_id IS DISTINCT FROM OLD.card_provider_reference_id THEN RAISE EXCEPTION 'Notification account is immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER immutable_event_account BEFORE UPDATE ON card_provider_events FOR EACH ROW EXECUTE FUNCTION guard_event_account();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Account routing and BIN history require forward migration.');
    }
};
