<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Laravel's starter user scaffolding is removed (owner's decision, 2026-09-18): Access builds its own
| customer and staff tables (handoff §7.1). The fallback tables for when Redis is not configured
| stay. These tests stop the scaffolding from creeping back in.
*/

uses(RefreshDatabase::class);

it('has no default users or password-reset tables', function (string $table) {
    expect(Schema::hasTable($table))->toBeFalse();
})->with(['users', 'password_reset_tokens']);

it('keeps the fallback tables used while Redis is not configured', function (string $table) {
    expect(Schema::hasTable($table))->toBeTrue();
})->with(['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs']);

it('stores a ULID account id in the fallback sessions table', function () {
    $column = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('table_name', 'sessions')
        ->where('column_name', 'user_id')
        ->first(['data_type', 'character_maximum_length']);

    expect($column?->data_type)->toBe('character')
        ->and($column?->character_maximum_length)->toBe(26);
});

it('points the leftover default auth provider at no model, and the User model is gone', function () {
    expect(config('auth.providers.users.model'))->toBeNull()
        ->and(class_exists('App\\Models\\User'))->toBeFalse();
});
