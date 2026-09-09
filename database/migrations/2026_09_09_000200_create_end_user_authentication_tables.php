<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('email')->nullable();
            $table->string('phone', 16)->nullable();
            $table->string('password_hash');
            $table->string('status', 24);
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampTz('phone_verified_at')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'email']);
            $table->unique(['tenant_id', 'phone']);
            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'status', 'created_at']);
        });

        Schema::create('user_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('user_id');
            $table->string('display_name')->nullable();
            $table->timestampsTz();
            $table->unique('user_id');
            $table->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
        });

        Schema::create('user_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('user_id');
            $table->string('locale', 16);
            $table->timestampsTz();
            $table->unique('user_id');
            $table->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
        });

        Schema::create('registration_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('channel', 16);
            $table->string('destination');
            $table->string('code_hash', 64);
            $table->string('status', 24);
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'destination', 'created_at']);
            $table->index(['tenant_id', 'status', 'expires_at']);
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('ACTIVE','SUSPENDED','DISABLED'))");
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_contact_required_check CHECK (email IS NOT NULL OR phone IS NOT NULL)');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_normalized_check CHECK (email IS NULL OR email = LOWER(BTRIM(email)))');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_phone_format_check CHECK (phone IS NULL OR phone ~ '^\\+[1-9][0-9]{7,14}$')");
        DB::statement("ALTER TABLE registration_challenges ADD CONSTRAINT registration_challenges_channel_check CHECK (channel IN ('EMAIL','PHONE'))");
        DB::statement("ALTER TABLE registration_challenges ADD CONSTRAINT registration_challenges_status_check CHECK (status IN ('PENDING','VERIFIED','EXPIRED','CANCELLED','LOCKED'))");
        DB::statement("ALTER TABLE registration_challenges ADD CONSTRAINT registration_challenges_destination_check CHECK ((channel = 'EMAIL' AND destination = LOWER(BTRIM(destination))) OR (channel = 'PHONE' AND destination ~ '^\\+[1-9][0-9]{7,14}$'))");
        DB::statement("CREATE UNIQUE INDEX registration_challenges_one_pending_contact ON registration_challenges (tenant_id, destination) WHERE status = 'PENDING'");
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_challenges');
        Schema::dropIfExists('user_preferences');
        Schema::dropIfExists('user_profiles');
        Schema::dropIfExists('users');
    }
};
