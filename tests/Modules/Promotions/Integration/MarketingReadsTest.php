<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariant;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariantHandler;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Application\Query\ListGiftLevels\ListGiftLevels;
use Modules\Promotions\Application\Query\ListGiftLevels\ListGiftLevelsHandler;
use Modules\Promotions\Application\Query\ListSegmentMembers\ListSegmentMembers;
use Modules\Promotions\Application\Query\ListSegmentMembers\ListSegmentMembersHandler;
use Modules\Promotions\Application\Query\ListSegments\ListSegments;
use Modules\Promotions\Application\Query\ListSegments\ListSegmentsHandler;
use Modules\Promotions\Application\Query\ViewMyCoupons\ViewMyCoupons;
use Modules\Promotions\Application\Query\ViewMyCoupons\ViewMyCouponsHandler;
use Modules\Promotions\Application\Query\ViewSegment\ViewSegment;
use Modules\Promotions\Application\Query\ViewSegment\ViewSegmentHandler;
use Modules\Promotions\Domain\Exception\SegmentNotFound;
use Modules\Promotions\Public\Enums\SegmentRule;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;

use function Pest\Laravel\seed;

/*
| The rest of Promotions' reads (promotions.md §1.5, §1.7, §3): a store's segments with how many they
| hold, a segment's members by name only, a store's gift levels with their gifts named, and a
| customer's own usable coupons — each for its own readers only.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * @param  array<string, mixed>  $with
 */
function readSegment(array $with = [], string $storeCode = 'sa'): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('promotions.segments')->insert([
        'id' => $id, 'store_id' => Fx::storeId($storeCode), 'name_ar' => 'شريحة', 'name_en' => 'Segment',
        'type' => 'STATIC', 'audience' => 'INDIVIDUAL', 'rule' => null, 'rule_months' => null, 'rule_orders' => null,
        'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(), ...$with,
    ]);

    return $id;
}

function addMember(string $segmentId, string $customerId): void
{
    DB::table('promotions.segment_members')->insert(['segment_id' => $segmentId, 'customer_id' => $customerId, 'added_at' => CarbonImmutable::now()]);
}

describe('segments', function () {
    it('lists a store\'s segments newest first, with how many each holds, a page at a time', function () {
        $now = CarbonImmutable::now()->startOfSecond();
        $old = readSegment(['name_en' => 'Old', 'created_at' => $now->subMinutes(2)]);
        $rule = readSegment(['name_en' => 'Quiet', 'type' => 'DYNAMIC', 'rule' => 'NO_ORDER_FOR', 'rule_months' => 6, 'created_at' => $now->subMinute(), 'refreshed_at' => $now]);
        $new = readSegment(['name_en' => 'New', 'created_at' => $now]);
        readSegment(['name_en' => 'Egypt'], 'eg');
        addMember($old, Fx::customer());
        addMember($old, Fx::customer('omar@example.test'));
        addMember($new, Fx::customer('lina@example.test'));
        Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::VIEW], ['sa']));

        $first = app(ListSegmentsHandler::class)->handle(new ListSegments(Fx::storeId('sa'), perPage: 2));
        $second = app(ListSegmentsHandler::class)->handle(new ListSegments(Fx::storeId('sa'), $first->nextAt, $first->nextId, 2));

        expect(array_map(static fn ($row): array => [$row->id, $row->memberCount], [...$first->rows, ...$second->rows]))->toBe([[$new, 1], [$rule, 0], [$old, 2]])
            ->and([$first->rows[1]->rule, $first->rows[1]->ruleMonths, $first->rows[1]->refreshedAt?->getTimestamp()])->toBe([SegmentRule::NoOrderFor, 6, $now->getTimestamp()])
            ->and($second->nextId)->toBeNull()
            ->and($first->rows[0]->storeId)->toBe(Fx::storeId('sa'));
    });

    it('reads one segment by its id for its page, with its store, and only for its own readers', function () {
        $now = CarbonImmutable::now()->startOfSecond();
        $rule = readSegment(['name_en' => 'Quiet', 'type' => 'DYNAMIC', 'audience' => 'COMPANY', 'rule' => 'NO_ORDER_FOR', 'rule_months' => 6, 'refreshed_at' => $now]);
        $egypt = readSegment([], 'eg');
        addMember($rule, Fx::customer());
        Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::VIEW], ['sa']));

        $row = app(ViewSegmentHandler::class)->handle(new ViewSegment(strtoupper($rule)));

        expect([$row->id, $row->storeId, $row->nameEn, $row->type->value, $row->audience->value, $row->rule, $row->ruleMonths, $row->memberCount, $row->refreshedAt?->getTimestamp()])
            ->toBe([$rule, Fx::storeId('sa'), 'Quiet', 'DYNAMIC', 'COMPANY', SegmentRule::NoOrderFor, 6, 1, $now->getTimestamp()])
            ->and(fn () => app(ViewSegmentHandler::class)->handle(new ViewSegment($egypt)))->toThrow(SegmentNotFound::class)
            ->and(fn () => app(ViewSegmentHandler::class)->handle(new ViewSegment('not-an-id')))->toThrow(SegmentNotFound::class);

        Fx::actAsStaff(Fx::staffWith(['access.customer.view'], ['*']));
        expect(fn () => app(ViewSegmentHandler::class)->handle(new ViewSegment($rule)))->toThrow(Unauthorized::class);
    });

    it('lists a segment\'s members by name only, a page at a time, whatever store they registered in', function () {
        $segment = readSegment();
        $sara = Fx::customer();
        $egypt = Fx::customer('omar@example.test', 'eg');
        addMember($segment, $sara);
        addMember($segment, $egypt);
        DB::table('access.customers')->where('id', $egypt)->update(['anonymized_at' => now()]);
        Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::SEGMENT_MANAGE], ['sa']));

        $first = app(ListSegmentMembersHandler::class)->handle(new ListSegmentMembers($segment, perPage: 1));
        $second = app(ListSegmentMembersHandler::class)->handle(new ListSegmentMembers($segment, $first->nextCustomerId, 1));
        $all = [...$first->rows, ...$second->rows];
        $byId = array_combine(array_map(static fn ($row): string => $row->customer->id, $all), $all);

        expect(array_keys($byId))->toEqualCanonicalizing([$sara, $egypt])
            ->and([$byId[$sara]->customer->firstName, $byId[$sara]->customer->lastName, $byId[$sara]->customer->anonymized])->toBe(['Sara', 'Ali', false])
            ->and($byId[$egypt]->customer->anonymized)->toBeTrue()
            ->and($second->nextCustomerId)->toBeNull();
    });

    it('reads a page of members in the same queries however many it holds', function () {
        $few = readSegment();
        $many = readSegment();
        addMember($few, Fx::customer());

        foreach (range(1, 6) as $n) {
            addMember($many, Fx::customer("m{$n}@example.test"));
        }
        Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::VIEW], ['sa']));
        $count = static function (string $segment): int {
            app(ListSegmentMembersHandler::class)->handle(new ListSegmentMembers($segment));
            $queries = Cx::recordQueries();
            app(ListSegmentMembersHandler::class)->handle(new ListSegmentMembers($segment));

            return count($queries);
        };

        expect($count($many))->toBe($count($few));
    });

    it('answers a segment of a store not covered as not found, and refuses anyone without the job', function () {
        $egypt = readSegment([], 'eg');
        Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::GIFT_MANAGE], ['sa']));

        expect(fn () => app(ListSegmentMembersHandler::class)->handle(new ListSegmentMembers($egypt)))->toThrow(SegmentNotFound::class)
            ->and(fn () => app(ListSegmentMembersHandler::class)->handle(new ListSegmentMembers('not-an-id')))->toThrow(SegmentNotFound::class)
            ->and(fn () => app(ListSegmentsHandler::class)->handle(new ListSegments(Fx::storeId('eg'))))->toThrow(Unauthorized::class);

        Fx::actAsStaff(Fx::staffWith(['access.customer.view'], ['*']));
        expect(fn () => app(ListSegmentMembersHandler::class)->handle(new ListSegmentMembers($egypt)))->toThrow(Unauthorized::class)
            ->and(fn () => app(ListSegmentsHandler::class)->handle(new ListSegments(Fx::storeId('sa'))))->toThrow(Unauthorized::class);
    });
});

describe('gift levels', function () {
    it('lists every level highest first, its gift named, whether it gives it now', function () {
        [$pen, $mug, $cup] = Px::ready(['60 cm', '80 cm', '100 cm'])['variants'];
        $now = CarbonImmutable::now()->startOfSecond();
        CarbonImmutable::setTestNow($now);
        $thousand = Pr::giftLevel($pen, 1000_00);
        $later = Pr::giftLevel($mug, 2000_00, with: ['starts_at' => $now->addDay()]);
        $off = Pr::giftLevel($cup, 500_00, with: ['active' => false]);
        $ended = Pr::giftLevel($mug, 700_00, with: ['ends_at' => $now]);
        Pr::giftLevel($pen, 900_00, 'eg');
        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($cup)));
        Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::VIEW], ['sa']));

        $rows = app(ListGiftLevelsHandler::class)->handle(new ListGiftLevels(Fx::storeId('sa')));

        expect(array_map(static fn ($row): array => [$row->id, $row->amount->minorUnits, $row->inEffect], $rows))->toBe([
            [$later, 2000_00, false], [$thousand, 1000_00, true], [$ended, 700_00, false], [$off, 500_00, false],
        ])
            ->and($rows[1]->variantCode)->not->toBeNull()
            ->and($rows[1]->productNameEn)->toStartWith('Drawer')
            ->and([$rows[1]->variantArchived, $rows[3]->variantArchived])->toBe([false, true])
            // Archived, but still Catalog's: its code and product are still named.
            ->and($rows[3]->variantCode)->not->toBeNull()
            ->and($rows[3]->productNameAr)->not->toBeNull();
    });

    it('reads every level in the same queries however many there are', function () {
        [$pen, $mug] = Px::ready(['60 cm', '80 cm'])['variants'];
        Pr::giftLevel($pen, 1000_00);
        Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::VIEW], ['sa']));
        $count = static function (): int {
            app(ListGiftLevelsHandler::class)->handle(new ListGiftLevels(Fx::storeId('sa')));
            $queries = Cx::recordQueries();
            app(ListGiftLevelsHandler::class)->handle(new ListGiftLevels(Fx::storeId('sa')));

            return count($queries);
        };
        $one = $count();

        foreach (range(2, 6) as $n) {
            Pr::giftLevel($n % 2 === 0 ? $mug : $pen, $n * 1000_00);
        }

        expect($count())->toBe($one);
    });

    it('keeps a store\'s levels for its readers, and an off store\'s for Super Admins', function () {
        Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::COUPON_MANAGE], ['sa']));
        expect(fn () => app(ListGiftLevelsHandler::class)->handle(new ListGiftLevels(Fx::storeId('eg'))))->toThrow(Unauthorized::class);

        $eg = Fx::storeId('eg');
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::VIEW], ['*']));
        expect(fn () => app(ListGiftLevelsHandler::class)->handle(new ListGiftLevels($eg)))->toThrow(Unauthorized::class);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        expect(app(ListGiftLevelsHandler::class)->handle(new ListGiftLevels($eg)))->toBe([]);
    });
});

describe('my coupons', function () {
    it('shows the coupons given to me that I can use now, soonest to end first, with my uses left', function () {
        $sara = Fx::customer();
        $omar = Fx::customer('omar@example.test');
        $now = CarbonImmutable::now()->startOfSecond();
        CarbonImmutable::setTestNow($now);
        $soon = Pr::coupon('SOON-1', with: ['eligibility' => 'ASSIGNED', 'ends_at' => $now->addDay(), 'max_uses_per_customer' => 2, 'minimum_minor' => 100_00, 'cap_minor' => 20_00]);
        $open = Pr::coupon('OPEN-1', with: ['eligibility' => 'ASSIGNED']);
        $usedUp = Pr::coupon('MINE-USED', with: ['eligibility' => 'ASSIGNED', 'max_uses_per_customer' => 1]);
        $off = Pr::coupon('OFF-1', with: ['eligibility' => 'ASSIGNED', 'active' => false]);
        $later = Pr::coupon('LATER-1', with: ['eligibility' => 'ASSIGNED', 'starts_at' => $now->addDay()]);
        $gone = Pr::coupon('GONE-1', with: ['eligibility' => 'ASSIGNED', 'max_uses_total' => 1]);
        $omars = Pr::coupon('OMARS-1', with: ['eligibility' => 'ASSIGNED']);
        $past = Pr::coupon('PAST-1', with: ['eligibility' => 'ASSIGNED', 'ends_at' => $now]);
        $shared = Pr::coupon('SHARED-1', with: ['eligibility' => 'ASSIGNED', 'max_uses_per_customer' => 1, 'ends_at' => $now->addDays(2)]);
        $egypt = Pr::coupon('EGYPT-1', 'eg', ['eligibility' => 'ASSIGNED']);
        Pr::assign($egypt, $sara, 'eg');
        Pr::coupon('PUBLIC-1');
        foreach ([$soon, $open, $usedUp, $off, $later, $gone, $past, $shared] as $coupon) {
            Pr::assign($coupon, $sara);
        }
        Pr::assign($omars, $omar);
        Pr::assign($shared, $omar);
        // Another customer's use never counts against mine.
        Pr::used($shared, $omar);
        Pr::used($soon, $sara);
        Pr::used($usedUp, $sara);
        Pr::used($gone, $omar);
        Fx::actAsCustomer($sara);

        $mine = app(ViewMyCouponsHandler::class)->handle(new ViewMyCoupons(Fx::storeId('sa')));

        expect(array_map(static fn ($row): array => [$row->code, $row->usesLeft], $mine))->toBe([['SOON-1', 1], ['SHARED-1', 1], ['OPEN-1', null]])
            ->and([$mine[0]->cap?->minorUnits, $mine[0]->minimum?->minorUnits, $mine[0]->minimum?->currencyCode, $mine[0]->percent])->toBe([20_00, 100_00, Pr::currency(), 15]);
    });

    it('is the customer\'s own: staff are refused, and an off store has none', function () {
        $sara = Fx::customer();
        $coupon = Pr::coupon('EGYPT-1', 'eg', ['eligibility' => 'ASSIGNED']);
        Pr::assign($coupon, $sara, 'eg');
        $eg = Fx::storeId('eg');
        Fx::actAsCustomer($sara);
        expect(app(ViewMyCouponsHandler::class)->handle(new ViewMyCoupons($eg)))->toHaveCount(1);

        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        Fx::actAsCustomer($sara);
        expect(app(ViewMyCouponsHandler::class)->handle(new ViewMyCoupons($eg)))->toBe([]);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        expect(fn () => app(ViewMyCouponsHandler::class)->handle(new ViewMyCoupons(Fx::storeId('sa'))))->toThrow(Unauthorized::class);
    });
});
