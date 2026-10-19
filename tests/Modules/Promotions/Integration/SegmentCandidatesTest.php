<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Promotions\Application\Command\SetSegmentMembers\SetSegmentMembers;
use Modules\Promotions\Application\Command\SetSegmentMembers\SetSegmentMembersHandler;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Application\Query\CandidateRow;
use Modules\Promotions\Application\Query\SearchSegmentCandidates\SearchSegmentCandidates;
use Modules\Promotions\Application\Query\SearchSegmentCandidates\SearchSegmentCandidatesHandler;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Exception\SegmentNotFound;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;

use function Pest\Laravel\seed;

/*
| The segment hand-picker's search (promotions.md §1.5, §9.2 #6; access.md amendment 72(b)): what staff
| type, found through Access, kept to the customers the segment may hold — its audience, not
| anonymized, registered in its store or who ordered there — by name only, each marked when already a
| member.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

function pickerSegment(string $audience = 'INDIVIDUAL', string $storeCode = 'sa'): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('promotions.segments')->insert([
        'id' => $id, 'store_id' => Fx::storeId($storeCode), 'name_ar' => 'مختارون', 'name_en' => 'Picked', 'type' => 'STATIC',
        'audience' => $audience, 'rule' => null, 'rule_months' => null, 'rule_orders' => null,
        'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(),
    ]);

    return $id;
}

/**
 * @return array<string, bool> customer id => already a member
 */
function candidates(string $segmentId, string $text): array
{
    $found = [];

    foreach (app(SearchSegmentCandidatesHandler::class)->handle(new SearchSegmentCandidates($segmentId, $text))->rows as $row) {
        expect($row)->toBeInstanceOf(CandidateRow::class);
        $found[$row->customer->id] = $row->member;
    }

    return $found;
}

it('finds the customers the segment may hold, by name, marked when already in it', function () {
    // Every customer the fixture registers is called Sara Ali: the name finds them all.
    $sara = Fx::customer('sara@example.test');
    $omar = Fx::customer('omar@example.test');
    $visitor = Fx::customer('visitor@example.test', 'eg');
    $stranger = Fx::customer('stranger@example.test', 'eg');
    $company = Fx::customer('company@example.test', 'sa', 'company');
    $gone = Fx::customer('gone@example.test');
    Pr::ordered($visitor, CarbonImmutable::now()->subMonth());
    DB::table('access.customers')->where('id', $gone)->update(['anonymized_at' => now()]);
    Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::SEGMENT_MANAGE], ['sa']));
    $segment = pickerSegment();
    app(SetSegmentMembersHandler::class)->handle(new SetSegmentMembers($segment, [$sara]));
    // A member of another segment only is not marked here.
    app(SetSegmentMembersHandler::class)->handle(new SetSegmentMembers(pickerSegment(), [$omar]));

    $page = app(SearchSegmentCandidatesHandler::class)->handle(new SearchSegmentCandidates($segment, 'Sara'));

    expect(candidates($segment, 'Sara'))->toEqual([$sara => true, $omar => false, $visitor => false])
        ->and(array_keys(candidates($segment, 'Sara')))->not->toContain($stranger, $company, $gone)
        ->and([$page->rows[0]->customer->firstName, $page->rows[0]->customer->lastName])->toBe(['Sara', 'Ali'])
        ->and($page->more)->toBeFalse();

    // A companies' segment holds the company only.
    expect(candidates(pickerSegment('COMPANY'), 'Sara'))->toBe([$company => false]);
});

it('says when more were found than are shown', function () {
    foreach (range(1, SearchSegmentCandidatesHandler::SHOWN + 1) as $n) {
        Fx::customer("c{$n}@example.test");
    }
    Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::SEGMENT_MANAGE], ['sa']));

    $page = app(SearchSegmentCandidatesHandler::class)->handle(new SearchSegmentCandidates(pickerSegment(), 'Sara'));

    expect($page->rows)->toHaveCount(SearchSegmentCandidatesHandler::SHOWN)
        ->and($page->more)->toBeTrue();
});

it('is for whoever manages the store\'s segments: another store\'s is not found, others are refused', function () {
    $egypt = pickerSegment(storeCode: 'eg');
    Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::SEGMENT_MANAGE], ['sa']));

    expect(fn () => candidates($egypt, 'Sara'))->toThrow(SegmentNotFound::class)
        ->and(fn () => candidates('not-an-id', 'Sara'))->toThrow(SegmentNotFound::class);

    Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::VIEW, PromotionsPermissions::COUPON_MANAGE], ['*']));
    expect(fn () => candidates($egypt, 'Sara'))->toThrow(Unauthorized::class);
});

it('passes Access\'s refusal of a text too short to search', function () {
    Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::SEGMENT_MANAGE], ['sa']));

    expect(fn () => candidates(pickerSegment(), 'Sa'))->toThrow(DomainError::class);
});

it('refuses a segment a rule fills: it is never picked by hand', function () {
    Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::SEGMENT_MANAGE], ['sa']));
    $ruled = strtolower((string) Str::ulid());
    DB::table('promotions.segments')->insert([
        'id' => $ruled, 'store_id' => Fx::storeId('sa'), 'name_ar' => 'قاعدة', 'name_en' => 'Ruled', 'type' => 'DYNAMIC',
        'audience' => 'INDIVIDUAL', 'rule' => 'NO_ORDERS_EVER', 'rule_months' => null, 'rule_orders' => null,
        'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(),
    ]);

    expect(Pr::thrown(fn () => candidates($ruled, 'Sara'), InvalidPromotionAttribute::class)->attribute)->toBe('segment_type');
});

it('finds by email only the customers the staff member may view', function () {
    $sara = Fx::customer('sara@example.test');
    $egypt = Fx::customer('omar@example.test', 'eg');
    Pr::ordered($egypt, CarbonImmutable::now()->subMonth());
    $segment = pickerSegment();

    // Viewing Saudi customers only: the Egyptian buyer's email names nobody; his name still finds him.
    Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::SEGMENT_MANAGE, 'access.customer.view'], ['sa']));

    expect(candidates($segment, 'sara@example'))->toBe([$sara => false])
        ->and(candidates($segment, 'omar@example'))->toBe([])
        ->and(array_keys(candidates($segment, 'Sara')))->toContain($egypt);
});

it('keeps a switched-off store\'s segments for Super Admins alone', function () {
    Fx::customer('sara@example.test');
    $egypt = pickerSegment(storeCode: 'eg');
    Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
    Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::SEGMENT_MANAGE], ['*']));

    expect(fn () => candidates($egypt, 'Sara'))->toThrow(Unauthorized::class);

    Fx::actAsStaff(Fx::staff(superAdmin: true));
    expect(candidates($egypt, 'Sara'))->toBe([]);
});
