<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Modules\Access\Application\Storefront\InMemoryCustomerAccountPages;
use Modules\Access\Application\Storefront\InMemoryShopperLines;
use Modules\Access\Public\Contracts\ShopperLine;
use Modules\Access\Public\Dto\CustomerAccountPageDto;
use Modules\Access\Public\Dto\ShopperLineDto;
use Modules\Access\Public\Enums\AccountType;
use Modules\Access\Public\Enums\ShopperLineTone;

/*
| What other modules add to the shop's frame (access.md amendment 50): pages beside the account's
| own tabs, and lines under the header.
*/

final class ShopFrameRegistriesTestLine implements ShopperLine
{
    /** @var list<array{string, AccountType, bool}> */
    public static array $asked = [];

    public function lineFor(string $customerId, AccountType $accountType, bool $emailVerified): ?ShopperLineDto
    {
        self::$asked[] = [$customerId, $accountType, $emailVerified];

        return $accountType === AccountType::Company ? new ShopperLineDto('Finish your application', 'storefront.account', ShopperLineTone::Info) : null;
    }
}

final class ShopFrameRegistriesTestSilentLine implements ShopperLine
{
    public function lineFor(string $customerId, AccountType $accountType, bool $emailVerified): ?ShopperLineDto
    {
        return null;
    }
}

describe('the pages other modules add to an account', function () {
    it('offers a page only to the account type it is for, and every page to every type when it names none, lowest position first', function () {
        $pages = new InMemoryCustomerAccountPages;
        $pages->register(
            new CustomerAccountPageDto('b2b', 'company', 'storefront.company', AccountType::Company, 20),
            new CustomerAccountPageDto('sales', 'orders', 'storefront.orders', null, 10),
        );

        expect(array_map(static fn (CustomerAccountPageDto $page): string => "{$page->module}.{$page->key}", $pages->for(AccountType::Company)))
            ->toBe(['sales.orders', 'b2b.company'])
            ->and(array_map(static fn (CustomerAccountPageDto $page): string => "{$page->module}.{$page->key}", $pages->for(AccountType::Individual)))
            ->toBe(['sales.orders']);
    });

    it('names a page from its own module\'s words', function () {
        expect((new CustomerAccountPageDto('b2b', 'company', 'storefront.company'))->labelKey())->toBe('b2b::account_pages.company');
    });

    it('refuses the same page twice, and two pages on one route', function (CustomerAccountPageDto $second) {
        $pages = new InMemoryCustomerAccountPages;
        $pages->register(new CustomerAccountPageDto('b2b', 'company', 'storefront.company'));

        expect(fn () => $pages->register($second))->toThrow(LogicException::class);
    })->with([
        'the same module and key' => [new CustomerAccountPageDto('b2b', 'company', 'storefront.other')],
        'the same route' => [new CustomerAccountPageDto('sales', 'orders', 'storefront.company')],
    ]);
});

describe('the lines under the shop\'s header', function () {
    beforeEach(fn () => ShopFrameRegistriesTestLine::$asked = []);

    it('asks each line, in order, with who the customer is, and keeps only the lines that answer', function () {
        $lines = new InMemoryShopperLines(new Container);
        $lines->register(ShopFrameRegistriesTestSilentLine::class);
        $lines->register(ShopFrameRegistriesTestLine::class);

        $said = $lines->for('01k0000000000000000000000a', AccountType::Company, true);

        expect($said)->toHaveCount(1)
            ->and($said[0]->text)->toBe('Finish your application')
            ->and(ShopFrameRegistriesTestLine::$asked)->toBe([['01k0000000000000000000000a', AccountType::Company, true]])
            ->and($lines->for('01k0000000000000000000000b', AccountType::Individual, false))->toBe([]);
    });

    it('refuses a class that is not a line, and a line registered twice', function () {
        $lines = new InMemoryShopperLines(new Container);
        $lines->register(ShopFrameRegistriesTestLine::class);

        expect(fn () => $lines->register(stdClass::class))->toThrow(LogicException::class)
            ->and(fn () => $lines->register(ShopFrameRegistriesTestLine::class))->toThrow(LogicException::class);
    });
});
