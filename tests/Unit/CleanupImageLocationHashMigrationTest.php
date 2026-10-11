<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('adds the missing image hash and derives it from retained encrypted evidence', function () {
    $connection = app('db')->connection();
    Schema::shouldReceive('hasColumn')->once()->with('data_cleanup_images', 'location_hash')->andReturn(false);
    Schema::shouldReceive('table')->once()->with('data_cleanup_images', Mockery::on(function ($callback) use ($connection) {
        $blueprint = new Blueprint($connection, 'data_cleanup_images');
        $callback($blueprint);
        $column = $blueprint->getColumns()[0];
        expect($column->name)->toBe('location_hash')->and($column->length)->toBe(64);
        return true;
    }));
    $query = Mockery::mock();
    DB::shouldReceive('table')->twice()->with('data_cleanup_images')->andReturn($query);
    $query->shouldReceive('whereNull')->twice()->with('location_hash')->andReturnSelf();
    $query->shouldReceive('orderBy')->once()->with('id')->andReturnSelf();
    $query->shouldReceive('where')->once()->with('id', 7)->andReturnSelf();
    Crypt::shouldReceive('decryptString')->once()->with('retained-ciphertext')->andReturn('{"disk":"private","key":"identity/example"}');
    $query->shouldReceive('update')->once()->with(['location_hash' => hash('sha256', "private\0identity/example")])->andReturn(1);
    $query->shouldReceive('chunkById')->once()->with(200, Mockery::on(function ($callback) {
        $callback(collect([(object) ['id' => 7, 'location_encrypted' => 'retained-ciphertext']]));
        return true;
    }));
    DB::shouldReceive('statement')->once()->with('ALTER TABLE data_cleanup_images ALTER COLUMN location_hash SET NOT NULL')->andReturn(true);
    $migration = require database_path('migrations/2026_10_11_220000_add_cleanup_image_location_hash.php');
    $migration->up();
});

it('keeps the existing image hash column and skips already populated evidence', function () {
    Schema::shouldReceive('hasColumn')->once()->with('data_cleanup_images', 'location_hash')->andReturn(true);
    Schema::shouldReceive('table')->never();
    $query = Mockery::mock();
    DB::shouldReceive('table')->once()->with('data_cleanup_images')->andReturn($query);
    $query->shouldReceive('whereNull')->once()->with('location_hash')->andReturnSelf();
    $query->shouldReceive('orderBy')->once()->with('id')->andReturnSelf();
    $query->shouldReceive('chunkById')->once()->with(200, Mockery::on(function ($callback) { $callback(collect()); return true; }));
    Crypt::shouldReceive('decryptString')->never();
    DB::shouldReceive('statement')->once()->with('ALTER TABLE data_cleanup_images ALTER COLUMN location_hash SET NOT NULL')->andReturn(true);
    $migration = require database_path('migrations/2026_10_11_220000_add_cleanup_image_location_hash.php');
    $migration->up();
    $migration->down();
});
