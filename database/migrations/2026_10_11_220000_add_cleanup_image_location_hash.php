<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('data_cleanup_images', 'location_hash')) {
            Schema::table('data_cleanup_images', function (Blueprint $table): void {
                $table->string('location_hash', 64)->nullable()->index();
            });
        }
        // Derive only the lookup hash; retain the encrypted original and retention dates.
        DB::table('data_cleanup_images')->whereNull('location_hash')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $location = json_decode(Crypt::decryptString($row->location_encrypted), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($location) || ! is_string($location['disk'] ?? null) || ! is_string($location['key'] ?? null)) {
                    throw new RuntimeException('Cleanup image location could not be validated.');
                }
                DB::table('data_cleanup_images')->where('id', $row->id)->whereNull('location_hash')
                    ->update(['location_hash' => hash('sha256', $location['disk']."\0".$location['key'])]);
            }
        });
        DB::statement('ALTER TABLE data_cleanup_images ALTER COLUMN location_hash SET NOT NULL');
    }

    public function down(): void
    {
        // Keep cleanup evidence and shared-image retention protection.
    }
};
