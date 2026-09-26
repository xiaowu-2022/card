<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_installation', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->timestampTz('started_at');
        });
        DB::table('inbox_installation')->insert(['id' => 1, 'started_at' => now()]);
        Schema::create('inbox_broadcasts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('actor_id')->references('id')->on('admin_users')->restrictOnDelete();
            $t->string('intent_hash', 64);
            $t->string('audience', 16);
            $t->string('title', 100);
            $t->text('body');
            $t->unsignedInteger('recipient_count');
            $t->timestampTz('created_at');
            $t->index(['tenant_id', 'created_at']);
        });
        Schema::create('inbox_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('broadcast_id')->nullable()->references('id')->on('inbox_broadcasts')->restrictOnDelete();
            $t->string('event_key', 200);
            $t->string('kind', 16);
            $t->string('template', 60)->nullable();
            $t->jsonb('parameters');
            $t->string('href', 255)->nullable();
            $t->timestampTz('occurred_at');
            $t->timestampTz('created_at');
            $t->timestampTz('delivered_at')->nullable();
            $t->unique(['tenant_id', 'user_id', 'event_key']);
            $t->unique(['tenant_id', 'user_id', 'id']);
            $t->index(['delivered_at', 'created_at']);
            $t->index('broadcast_id');
        });
        Schema::create('inbox_receipts', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('tenant_id');
            $t->uuid('user_id');
            $t->uuid('event_id')->unique();
            $t->timestampTz('delivered_at');
            $t->timestampTz('read_at')->nullable();
            $t->foreign(['tenant_id', 'user_id', 'event_id'])->references(['tenant_id', 'user_id', 'id'])->on('inbox_events')->restrictOnDelete();
            $t->index(['tenant_id', 'user_id', 'read_at', 'id']);
        });
        // Scope integrity, and immutable intent even when delivery/read state changes.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_inbox_scope() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Inbox records cannot be deleted'; END IF;
                IF TG_TABLE_NAME = 'inbox_events' THEN
                    IF NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.user_id AND tenant_id = NEW.tenant_id) THEN RAISE EXCEPTION 'Inbox user scope mismatch'; END IF;
                    IF NEW.broadcast_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM inbox_broadcasts WHERE id = NEW.broadcast_id AND tenant_id = NEW.tenant_id) THEN RAISE EXCEPTION 'Inbox broadcast scope mismatch'; END IF;
                    IF TG_OP = 'UPDATE' AND (to_jsonb(NEW) - 'delivered_at') IS DISTINCT FROM (to_jsonb(OLD) - 'delivered_at') THEN RAISE EXCEPTION 'Inbox event is immutable'; END IF;
                ELSIF TG_TABLE_NAME = 'inbox_receipts' THEN
                    IF TG_OP = 'UPDATE' AND ((to_jsonb(NEW) - 'read_at') IS DISTINCT FROM (to_jsonb(OLD) - 'read_at') OR (OLD.read_at IS NOT NULL AND NEW.read_at IS DISTINCT FROM OLD.read_at)) THEN RAISE EXCEPTION 'Inbox receipt is immutable'; END IF;
                ELSIF TG_OP = 'UPDATE' THEN RAISE EXCEPTION 'Inbox broadcast is immutable';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER inbox_event_guard BEFORE INSERT OR UPDATE OR DELETE ON inbox_events FOR EACH ROW EXECUTE FUNCTION guard_inbox_scope();
            CREATE TRIGGER inbox_receipt_guard BEFORE UPDATE OR DELETE ON inbox_receipts FOR EACH ROW EXECUTE FUNCTION guard_inbox_scope();
            CREATE TRIGGER inbox_broadcast_guard BEFORE UPDATE OR DELETE ON inbox_broadcasts FOR EACH ROW EXECUTE FUNCTION guard_inbox_scope();
            SQL);
        foreach (['notifications.read', 'notifications.send'] as $name) {
            $id = DB::table('permissions')->where('name', $name)->value('id');
            if (! $id) {
                $id = (string) Str::uuid();
                DB::table('permissions')->insert(['id' => $id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach (DB::table('roles')->where('scope_type', 'PLATFORM')->whereIn('name', ['PLATFORM_OWNER', 'PLATFORM_ADMIN'])->pluck('id') as $role) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_receipts');
        Schema::dropIfExists('inbox_events');
        Schema::dropIfExists('inbox_broadcasts');
        Schema::dropIfExists('inbox_installation');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_inbox_scope()');
    }
};
