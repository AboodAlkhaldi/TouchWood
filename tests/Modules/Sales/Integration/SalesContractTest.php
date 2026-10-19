<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Modules\Sales\Infrastructure\External\NothingSoldYet;
use Modules\Sales\Public\Contracts\SalesApi;

/*
| Sales's public contract, declared before Sales is built (sales.md §2.1; feedback.md §2.1): it resolves,
| and until the build answers that nothing was delivered and no return completed.
*/

it('resolves the contract to the placeholder, which knows no purchase and no return', function () {
    $sales = app(SalesApi::class);
    $id = strtolower((string) Str::ulid());

    expect($sales)->toBeInstanceOf(NothingSoldYet::class)
        ->and($sales->deliveredPurchase($id, $id))->toBeNull()
        ->and($sales->returnedProducts($id))->toBeNull();
});
