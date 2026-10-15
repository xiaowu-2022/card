<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE user_cards ALTER COLUMN last4 DROP NOT NULL');
        DB::statement('ALTER TABLE user_cards DROP CONSTRAINT user_cards_safe_identity_check');
        DB::statement("ALTER TABLE user_cards ADD CONSTRAINT user_cards_safe_identity_check CHECK (provider IN ('PHOTONPAY','U1') AND card_currency='USD' AND (last4 IS NULL OR last4 ~ '^[0-9]{4}$') AND masked_pan !~ '^[0-9]{12,19}$')");
    }
    public function down(): void
    {
        if (DB::table('user_cards')->whereNull('last4')->exists()) throw new RuntimeException('Synchronize pending card details before rollback.');
        DB::statement('ALTER TABLE user_cards ALTER COLUMN last4 SET NOT NULL');
    }
};
