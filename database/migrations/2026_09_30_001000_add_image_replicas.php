<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stored_images', function (Blueprint $t) {
            $t->string('backup_key')->nullable();
            $t->char('backup_sha256', 64)->nullable();
            $t->boolean('oss_pending')->default(false)->index();
        });
    }

    public function down(): void
    {
        throw new LogicException('Retain image replicas; roll forward instead of removing backup references.');
    }
};
