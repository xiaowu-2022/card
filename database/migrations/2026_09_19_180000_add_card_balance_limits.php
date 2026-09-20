<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_cards', function (Blueprint $table): void {
            $table->decimal('balance_limit', 20, 8)->nullable();
        });
        Schema::table('card_management_orders', function (Blueprint $table): void {
            $table->decimal('requested_amount', 20, 8)->nullable();
            $table->decimal('overflow_amount', 20, 8)->nullable();
            $table->decimal('balance_limit_snapshot', 20, 8)->nullable();
        });
        DB::statement('ALTER TABLE user_cards ADD CONSTRAINT card_balance_limit_valid CHECK (balance_limit IS NULL OR (balance_limit >= 0 AND balance_limit = trunc(balance_limit, 2)))');
        DB::statement("ALTER TABLE card_management_orders ADD CONSTRAINT card_load_split_valid CHECK ((requested_amount IS NULL AND overflow_amount IS NULL AND balance_limit_snapshot IS NULL) OR (kind = 'LOAD' AND requested_amount IS NOT NULL AND overflow_amount IS NOT NULL AND requested_amount = amount + overflow_amount AND overflow_amount >= 0 AND (balance_limit_snapshot IS NULL OR balance_limit_snapshot >= amount)))");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_card_load_split() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (NEW.requested_amount, NEW.overflow_amount, NEW.balance_limit_snapshot)
                    IS DISTINCT FROM (OLD.requested_amount, OLD.overflow_amount, OLD.balance_limit_snapshot) THEN
                    RAISE EXCEPTION 'Card load split is immutable' USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER card_load_split_immutable BEFORE UPDATE ON card_management_orders
                FOR EACH ROW EXECUTE FUNCTION guard_card_load_split();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER card_load_split_immutable ON card_management_orders; DROP FUNCTION guard_card_load_split(); ALTER TABLE card_management_orders DROP CONSTRAINT card_load_split_valid; ALTER TABLE user_cards DROP CONSTRAINT card_balance_limit_valid;');
        Schema::table('card_management_orders', fn (Blueprint $table) => $table->dropColumn(['requested_amount', 'overflow_amount', 'balance_limit_snapshot']));
        Schema::table('user_cards', fn (Blueprint $table) => $table->dropColumn('balance_limit'));
    }
};
