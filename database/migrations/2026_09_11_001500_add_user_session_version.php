<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->bigInteger('session_version')->default(0);
        });
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_session_version_nonnegative CHECK (session_version >= 0)');
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('session_version'));
    }
};
