<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep Tenant -> User lock order during this transactional backfill.
        DB::statement('LOCK TABLE tenants IN EXCLUSIVE MODE');
        Schema::table('users', function (Blueprint $table): void {
            $table->string('account_id', 12)->nullable();
            $table->unique(['tenant_id', 'account_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION allocate_user_account_id(owner_tenant uuid, registered_at timestamptz)
            RETURNS varchar(12) LANGUAGE plpgsql VOLATILE AS $$
            DECLARE
                tenant_timezone text;
                date_prefix text;
                assigned_id varchar(12);
            BEGIN
                -- Registration already owns this lock. All other insert paths
                -- take the same lock so concurrent registrations cannot collide.
                SELECT timezone INTO tenant_timezone FROM tenants
                WHERE id = owner_tenant FOR UPDATE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Account company does not exist' USING ERRCODE = '23503';
                END IF;
                date_prefix := to_char(COALESCE(registered_at, CURRENT_TIMESTAMP) AT TIME ZONE tenant_timezone, 'YYYYMMDD');
                SELECT date_prefix || lpad(suffix::text, 4, '0') INTO assigned_id
                FROM generate_series(0, 9999) AS choices(suffix)
                WHERE NOT EXISTS (
                    SELECT 1 FROM users WHERE tenant_id = owner_tenant
                    AND account_id = date_prefix || lpad(suffix::text, 4, '0')
                )
                ORDER BY random() LIMIT 1;
                IF assigned_id IS NULL THEN
                    RAISE EXCEPTION 'Account ID capacity exhausted' USING ERRCODE = 'P2001';
                END IF;
                RETURN assigned_id;
            END;
            $$;

            DO $$
            DECLARE existing_user record;
            BEGIN
                -- Explicit maintenance iteration over tenants and exact users.
                FOR existing_user IN SELECT id, tenant_id, created_at FROM users ORDER BY tenant_id, id LOOP
                    UPDATE users SET account_id = allocate_user_account_id(existing_user.tenant_id, existing_user.created_at)
                    WHERE tenant_id = existing_user.tenant_id AND id = existing_user.id;
                END LOOP;
            END;
            $$;

            ALTER TABLE users ALTER COLUMN account_id SET NOT NULL;
            ALTER TABLE users ADD CONSTRAINT users_account_id_format_check CHECK (account_id ~ '^[0-9]{12}$');

            CREATE FUNCTION assign_user_account_id() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.account_id IS NOT NULL THEN
                    RAISE EXCEPTION 'Account ID is server assigned' USING ERRCODE = '23514';
                END IF;
                NEW.account_id := allocate_user_account_id(NEW.tenant_id, NEW.created_at);
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER users_assign_account_id BEFORE INSERT ON users
            FOR EACH ROW EXECUTE FUNCTION assign_user_account_id();

            CREATE FUNCTION protect_user_account_id() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.account_id IS DISTINCT FROM OLD.account_id THEN
                    RAISE EXCEPTION 'Account ID is immutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER users_protect_account_id BEFORE UPDATE ON users
            FOR EACH ROW EXECUTE FUNCTION protect_user_account_id();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER users_protect_account_id ON users;
            DROP FUNCTION protect_user_account_id();
            DROP TRIGGER users_assign_account_id ON users;
            DROP FUNCTION assign_user_account_id();
            DROP FUNCTION allocate_user_account_id(uuid, timestamptz);
            ALTER TABLE users DROP CONSTRAINT users_account_id_format_check;
            SQL);
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'account_id']);
            $table->dropColumn('account_id');
        });
    }
};
