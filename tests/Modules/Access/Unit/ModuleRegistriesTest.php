<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Modules\Access\Application\Customer\InMemoryCustomerPageSections;
use Modules\Access\Application\Storefront\InMemoryCustomerAccountPages;
use Modules\Access\Public\Contracts\CustomerAccountPageCondition;
use Modules\Access\Public\Contracts\CustomerPageSection;
use Modules\Access\Public\Dto\CustomerAccountPageDto;
use Modules\Access\Public\Dto\CustomerPageSectionDto;
use Modules\Access\Public\Enums\AccountType;

/*
| Access amendment 72: an account page offered only while its own condition says so (d), and the
| sections other modules add to the staff customer page (a).
*/

final class ModuleRegistriesTestCondition implements CustomerAccountPageCondition
{
    /** @var list<string> */
    public static array $asked = [];

    public static bool $answer = true;

    public function offers(string $customerId): bool
    {
        self::$asked[] = $customerId;

        return self::$answer;
    }
}

final class ModuleRegistriesTestSection implements CustomerPageSection
{
    /** @return array{customer: string} */
    public function sectionFor(string $customerId): array
    {
        return ['customer' => $customerId];
    }
}

final class ModuleRegistriesTestSilentSection implements CustomerPageSection
{
    public function sectionFor(string $customerId): ?array
    {
        return null;
    }
}

describe('an account page offered on a condition', function () {
    beforeEach(function () {
        ModuleRegistriesTestCondition::$asked = [];
        ModuleRegistriesTestCondition::$answer = true;
    });

    it('offers a page only while its condition says so, asking it for the customer, and only for the account type it is for', function () {
        $pages = new InMemoryCustomerAccountPages(new Container);
        $pages->register(
            new CustomerAccountPageDto('loyalty', 'points', 'storefront.loyalty.points', condition: ModuleRegistriesTestCondition::class),
            new CustomerAccountPageDto('b2b', 'company', 'storefront.company', AccountType::Company, 10, ModuleRegistriesTestCondition::class),
        );
        $names = static fn (array $offered): array => array_map(static fn (CustomerAccountPageDto $page): string => $page->name(), $offered);

        expect($names($pages->offeredTo(AccountType::Individual, 'c1')))->toBe(['loyalty.points'])
            // Never asked for a page the account type does not get.
            ->and(ModuleRegistriesTestCondition::$asked)->toBe(['c1']);

        ModuleRegistriesTestCondition::$answer = false;

        expect($pages->offeredTo(AccountType::Company, 'c2'))->toBe([])
            // The list itself is the registry's, condition or not.
            ->and($names($pages->for(AccountType::Company)))->toBe(['loyalty.points', 'b2b.company']);
    });

    it('refuses a condition that is not one', function () {
        $pages = new InMemoryCustomerAccountPages(new Container);

        expect(fn () => $pages->register(new CustomerAccountPageDto('loyalty', 'points', 'storefront.loyalty.points', condition: stdClass::class)))->toThrow(LogicException::class);
    });
});

describe('the staff customer page\'s sections', function () {
    it('answers each section\'s data in position order, leaving out a section with nothing for this reader, and gathers their words once', function () {
        $sections = new InMemoryCustomerPageSections(new Container);
        $sections->register(
            new CustomerPageSectionDto('sales', 'orders', ModuleRegistriesTestSection::class, ['sales::admin', 'admin'], 20),
            new CustomerPageSectionDto('loyalty', 'points', ModuleRegistriesTestSection::class, ['loyalty::admin_points', 'admin'], 10),
            new CustomerPageSectionDto('promotions', 'coupons', ModuleRegistriesTestSilentSection::class, ['promotions::admin'], 5),
        );

        expect($sections->answersFor('c1'))->toBe([
            ['module' => 'loyalty', 'key' => 'points', 'data' => ['customer' => 'c1']],
            ['module' => 'sales', 'key' => 'orders', 'data' => ['customer' => 'c1']],
        ])->and($sections->words())->toEqualCanonicalizing(['sales::admin', 'admin', 'loyalty::admin_points', 'promotions::admin']);
    });

    it('refuses a class that is not a section, and a section registered twice', function () {
        $sections = new InMemoryCustomerPageSections(new Container);
        $sections->register(new CustomerPageSectionDto('loyalty', 'points', ModuleRegistriesTestSection::class));

        expect(fn () => $sections->register(new CustomerPageSectionDto('loyalty', 'other', stdClass::class)))->toThrow(LogicException::class)
            ->and(fn () => $sections->register(new CustomerPageSectionDto('loyalty', 'points', ModuleRegistriesTestSilentSection::class)))->toThrow(LogicException::class);
    });
});
