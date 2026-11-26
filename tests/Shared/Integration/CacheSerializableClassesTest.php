<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Platform\Public\Dto\MoneyData;

/*
| The cache gives back only the objects config/cache.php names (`serializable_classes`): the price a screen shows,
| kept inside the shop's cached pages, returns as itself, while any other object stays an incomplete one, as Laravel's
| guard against gadget chains intends. Read through the database store, as the live site's cache is.
*/

uses(RefreshDatabase::class);

it('gives back a kept price as a price, inside the arrays the shop pages keep', function () {
    $store = Cache::store('database');
    $store->put('cache-classes-price', ['variants' => [['price' => new MoneyData('⃁ 0.16', 16)]]], 60);

    $kept = $store->get('cache-classes-price');

    expect($kept['variants'][0]['price'])->toBeInstanceOf(MoneyData::class)
        ->and($kept['variants'][0]['price']->text)->toBe('⃁ 0.16')
        ->and($kept['variants'][0]['price']->minor)->toBe(16);
});

it('still gives back any other object as an incomplete one', function () {
    $store = Cache::store('database');
    $store->put('cache-classes-other', ['thing' => new ArrayObject([1, 2])], 60);

    expect($store->get('cache-classes-other')['thing'])->toBeInstanceOf(__PHP_Incomplete_Class::class);
});
