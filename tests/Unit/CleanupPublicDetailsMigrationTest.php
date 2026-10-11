<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('adds nullable preview metadata only when the legacy schema lacks it', function () {
    Schema::shouldReceive('hasColumn')->once()->with('data_cleanup_items', 'public_details')->andReturn(false);
    Schema::shouldReceive('table')->once()->with('data_cleanup_items', Mockery::on(function ($callback) {
        $blueprint = new Blueprint(app('db')->connection(), 'data_cleanup_items');
        $callback($blueprint);
        $columns = $blueprint->getColumns();
        expect($columns)->toHaveCount(1)->and($columns[0]->name)->toBe('public_details')
            ->and($columns[0]->type)->toBe('jsonb')->and($columns[0]->nullable)->toBeTrue();
        return true;
    }));
    $migration = require database_path('migrations/2026_10_11_210000_add_cleanup_item_public_details.php');
    $migration->up();
});

it('leaves an existing metadata column and immutable evidence unchanged', function () {
    Schema::shouldReceive('hasColumn')->once()->with('data_cleanup_items', 'public_details')->andReturn(true);
    Schema::shouldReceive('table')->never();
    $migration = require database_path('migrations/2026_10_11_210000_add_cleanup_item_public_details.php');
    $migration->up();
    $migration->down();
});
