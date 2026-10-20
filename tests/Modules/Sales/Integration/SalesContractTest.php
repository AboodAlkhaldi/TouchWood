<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Sales\Infrastructure\Eloquent\DatabaseSalesApi;
use Modules\Sales\Public\Contracts\SalesApi;

/*
| Sales's public contract (sales.md §2.1; feedback.md §2.1), answered from its own deliveries and completed
| returns since step S10 — the purchases and returns themselves in ReturnCompletionTest.
*/

uses(RefreshDatabase::class);

it('resolves the contract to Sales own reads, which know no purchase and no return of an unknown or malformed id', function (string $id) {
    $sales = app(SalesApi::class);

    expect($sales)->toBeInstanceOf(DatabaseSalesApi::class)
        ->and($sales->deliveredPurchase($id, $id))->toBeNull()
        ->and($sales->returnedProducts($id))->toBeNull();
})->with([
    'unknown' => [strtolower((string) Str::ulid())],
    'malformed' => ['not-an-id'],
]);
