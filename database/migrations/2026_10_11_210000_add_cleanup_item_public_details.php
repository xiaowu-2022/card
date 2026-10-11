<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('data_cleanup_items', 'public_details')) {
            Schema::table('data_cleanup_items', function (Blueprint $table): void {
                // Existing immutable manifests remain untouched; only new previews
                // receive the safe display metadata produced by the planner.
                $table->jsonb('public_details')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Retain immutable cleanup evidence on rollback.
    }
};
