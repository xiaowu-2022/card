<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('user_cards', fn (Blueprint $table) => $table->text('sensitive_details_encrypted')->nullable()); }
    public function down(): void { Schema::table('user_cards', fn (Blueprint $table) => $table->dropColumn('sensitive_details_encrypted')); }
};
