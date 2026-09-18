<?php

declare(strict_types=1);

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

it('never takes a correlation id from the caller, however well-formed', function (string $incoming) {
    // Owner's decision (2026-09-18): a caller must not choose what goes into the audit log.
    $id = withHeader('X-Correlation-Id', $incoming)->get('/_test/ok')->headers->get('X-Correlation-Id');

    expect($id)->not->toBe($incoming)->toMatch('/^[0-7][0-9a-hjkmnp-tv-z]{25}$/');
})->with([
    'well-formed' => ['edge-7f3a9c21'],
    'a valid-looking ULID' => ['01j8z3k4m5n6p7q8r9s0t1v2w3'],
    'too short' => ['abc'],
    'unsafe characters' => ['id with spaces; drop'],
]);

it('puts the same correlation id in an error body and its header', function () {
    $response = getJson('/_test/fails');
    $header = $response->headers->get('X-Correlation-Id');

    // Both missing would also be "the same", so the header must really be there.
    expect($header)->toMatch('/^[0-7][0-9a-hjkmnp-tv-z]{25}$/')
        ->and($response->json('correlation_id'))->toBe($header);
});
