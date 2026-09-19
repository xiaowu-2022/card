<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_tron_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->string('deposit_address', 128)->nullable();
            $table->timestamps();
        });
        DB::table('asset_tron_settings')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_tron_settings');
    }
};
