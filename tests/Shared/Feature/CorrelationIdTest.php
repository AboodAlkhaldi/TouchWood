<?php

use Illuminate\Support\Facades\Route;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeader;

beforeEach(function () {
    Route::get('/_test/ok', fn () => 'ok');
    Route::get('/_test/fails', fn () => abort(404));
});

it('gives every response a correlation id', function () {
    $id = get('/_test/ok')->assertOk()->headers->get('X-Correlation-Id');

    expect($id)->toMatch('/^[0-7][0-9a-hjkmnp-tv-z]{25}$/');
});

it('keeps a well-formed correlation id sent by the caller', function () {
    withHeader('X-Correlation-Id', 'edge-7f3a9c21')
        ->get('/_test/ok')
        ->assertHeader('X-Correlation-Id', 'edge-7f3a9c21');
});

it('replaces a malformed correlation id', function (string $incoming) {
    $id = withHeader('X-Correlation-Id', $incoming)->get('/_test/ok')->headers->get('X-Correlation-Id');

    expect($id)->not->toBe($incoming)->toMatch('/^[0-7][0-9a-hjkmnp-tv-z]{25}$/');
})->with([
    'too short' => ['abc'],
    'unsafe characters' => ['id with spaces; drop'],
    'too long' => [str_repeat('a', 65)],
]);

it('puts the same correlation id in an error body and its header', function () {
    $response = getJson('/_test/fails');

    expect($response->json('correlation_id'))->toBe($response->headers->get('X-Correlation-Id'));
});
