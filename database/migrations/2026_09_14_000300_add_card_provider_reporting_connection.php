<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_card_provider_references', function (Blueprint $table): void {
            $table->text('photonpay_reporting_encrypted')->nullable();
        });
    }

    public function down(): void
    {
        throw new LogicException('Preserve provider connections; use a forward migration.');
    }
};
