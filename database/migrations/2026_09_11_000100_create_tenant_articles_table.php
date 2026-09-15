<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_articles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('article_key', 32);
            $table->string('locale', 10);
            $table->text('body');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'article_key', 'locale']);
        });
        DB::statement("ALTER TABLE tenant_articles ADD CONSTRAINT tenant_articles_key_check CHECK (article_key IN ('terms', 'privacy', 'account-closure'))");
        DB::statement("ALTER TABLE tenant_articles ADD CONSTRAINT tenant_articles_locale_check CHECK (locale IN ('en', 'zh-CN', 'ms', 'es'))");
        DB::statement('ALTER TABLE tenant_articles ADD CONSTRAINT tenant_articles_body_length_check CHECK (char_length(body) <= 50000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_articles');
    }
};
