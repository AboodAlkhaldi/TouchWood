<?php

declare(strict_types=1);

use Modules\Content\Application\Query\Documents\LinkMatch;

/*
| Whether a link typed by staff names a page (content.md §1.1 rule 16; PR 3 review): both sides brought to one
| spelling - fragment and query dropped, scheme and host lower-cased, :443 dropped, www. read as the host, the path
| percent-decoded, one trailing slash dropped.
*/

function linkMatchOf(): LinkMatch
{
    return new LinkMatch(['/pages/الضمان', '/pages/warranty'], 'sa', 'Shop.Example');
}

it('names a page by its path, encoded either case, with a trailing slash, a part or a query', function (string $link) {
    expect(linkMatchOf()->names($link))->toBeTrue();
})->with([
    'as kept' => ['/pages/الضمان'],
    'encoded in capitals' => ['/pages/'.rawurlencode('الضمان')],
    'encoded in small letters' => ['/pages/'.strtolower(rawurlencode('الضمان'))],
    'with a trailing slash' => ['/pages/warranty/'],
    'to a part of it' => ['/pages/warranty#terms'],
    'with a query' => ['/pages/warranty?from=mail'],
]);

it('names a page in full on the shop\'s own host, however the host is written', function (string $link) {
    expect(linkMatchOf()->names($link))->toBeTrue();
})->with([
    'as the host' => ['https://shop.example/sa/en/pages/warranty'],
    'in capitals' => ['HTTPS://SHOP.EXAMPLE/sa/ar/pages/warranty'],
    'with its port' => ['https://shop.example:443/sa/en/pages/warranty'],
    'with www.' => ['https://www.shop.example/sa/en/pages/warranty/'],
    'encoded' => ['https://shop.example/sa/ar/pages/'.strtolower(rawurlencode('الضمان'))],
]);

it('names nothing else: another page, another host, another store, another port, a longer path', function (string $link) {
    expect(linkMatchOf()->names($link))->toBeFalse();
})->with([
    'another page' => ['/pages/warranty-2'],
    'a longer path' => ['/pages/warranty/terms'],
    'another host' => ['https://elsewhere.example/sa/en/pages/warranty'],
    'another store' => ['https://shop.example/ae/en/pages/warranty'],
    'another port' => ['https://shop.example:8443/sa/en/pages/warranty'],
    'no store and language' => ['https://shop.example/pages/warranty'],
]);

it('matches no full address when the shop has no host', function () {
    expect((new LinkMatch(['/pages/warranty'], 'sa', ''))->names('https://shop.example/sa/en/pages/warranty'))->toBeFalse()
        ->and((new LinkMatch(['/pages/warranty'], 'sa', ''))->names('/pages/warranty'))->toBeTrue();
});
