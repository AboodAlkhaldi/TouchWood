<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Promotions\Application\Command\ChangeSegment\ChangeSegment;
use Modules\Promotions\Application\Command\ChangeSegment\ChangeSegmentHandler;
use Modules\Promotions\Application\Command\CreateSegment\CreateSegment;
use Modules\Promotions\Application\Command\CreateSegment\CreateSegmentHandler;
use Modules\Promotions\Application\Command\DeleteSegment\DeleteSegment;
use Modules\Promotions\Application\Command\DeleteSegment\DeleteSegmentHandler;
use Modules\Promotions\Application\Command\RefreshSegment\RefreshSegment;
use Modules\Promotions\Application\Command\RefreshSegment\RefreshSegmentHandler;
use Modules\Promotions\Application\Command\RefreshSegments\RefreshSegments;
use Modules\Promotions\Application\Command\RefreshSegments\RefreshSegmentsHandler;
use Modules\Promotions\Application\Command\SetSegmentMembers\SetSegmentMembers;
use Modules\Promotions\Application\Command\SetSegmentMembers\SetSegmentMembersHandler;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Application\Segment\SegmentMembership;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Exception\SegmentNotFound;
use Modules\Promotions\Domain\Model\Segment;
use Modules\Promotions\Domain\Model\SegmentTerms;
use Modules\Promotions\Domain\Repository\OrderFacts;
use Modules\Promotions\Domain\Repository\Segments;
use Modules\Promotions\Infrastructure\Queue\RefreshSegmentsJob;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;

use function Pest\Laravel\seed;

/*
| Customer segments (promotions.md §1.5, §3; the scenarios of §8 "Assignments and segments"): picked by
| hand or filled by one rule over the store's placed orders — every one counts — of one audience, never
| an anonymized customer; refreshed now or nightly with the same members; read in pages and batches.
| A segment's type and audience never change (owner, 2026-10-18, answer 23).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * @param  list<string>  $stores
 */
function segmentManager(array $stores = ['sa']): void
{
    Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::SEGMENT_MANAGE], $stores));
}

function segmentCreate(string $type = 'DYNAMIC', string $audience = 'INDIVIDUAL', ?string $rule = 'NO_ORDERS_EVER', ?int $months = null, ?int $orders = null, string $storeCode = 'sa'): string
{
    return app(CreateSegmentHandler::class)->handle(new CreateSegment(Fx::storeId($storeCode), 'شريحة', 'Segment', $type, $audience, $rule, $months, $orders));
}

/** @return list<string> */
function segmentMembers(string $segmentId): array
{
    return array_values(array_map(strval(...), DB::table('promotions.segment_members')->where('segment_id', $segmentId)->orderBy('customer_id')->pluck('customer_id')->all()));
}

/**
 * @param  list<string>  $ids
 * @return list<string>
 */
function sortedIds(array $ids): array
{
    sort($ids);

    return $ids;
}

describe('the rules', function () {
    it('NO_ORDERS_EVER holds only customers registered in the store who never ordered there, of the audience, not anonymized', function () {
        $never = Fx::customer('never@example.test');
        $ordered = Fx::customer('ordered@example.test');
        $gone = Fx::customer('gone@example.test');
        Fx::customer('firm@example.test', 'sa', 'company');
        Fx::customer('egypt@example.test', 'eg');
        Pr::ordered($ordered, CarbonImmutable::now()->subYears(2));
        DB::table('access.customers')->where('id', $gone)->update(['anonymized_at' => now()]);
        segmentManager();

        expect(segmentMembers(segmentCreate()))->toBe([$never]);
    });

    it('NO_ORDER_FOR 6 months holds those whose last order is older, never one who never ordered', function () {
        $lapsed = Fx::customer('lapsed@example.test');
        $recent = Fx::customer('recent@example.test');
        Fx::customer('never@example.test');
        // Registered in Egypt, ordered in Saudi long ago: a member of Saudi's segment.
        $visitor = Fx::customer('visitor@example.test', 'eg');
        Pr::ordered($lapsed, CarbonImmutable::now()->subMonths(8));
        Pr::ordered($recent, CarbonImmutable::now()->subMonths(8));
        Pr::ordered($recent, CarbonImmutable::now()->subMonths(2));
        Pr::ordered($visitor, CarbonImmutable::now()->subMonths(7));
        segmentManager();

        expect(segmentMembers(segmentCreate(rule: 'NO_ORDER_FOR', months: 6)))->toBe(sortedIds([$lapsed, $visitor]));
    });

    it('AT_LEAST_ORDERS 5 in 12 months counts every placed order, the older ones not', function () {
        $frequent = Fx::customer('frequent@example.test');
        $almost = Fx::customer('almost@example.test');
        foreach (range(1, 5) as $n) {
            Pr::ordered($frequent, CarbonImmutable::now()->subMonths($n));
        }
        foreach (range(1, 4) as $n) {
            Pr::ordered($almost, CarbonImmutable::now()->subMonths($n));
        }
        Pr::ordered($almost, CarbonImmutable::now()->subMonths(13));
        segmentManager();

        expect(segmentMembers(segmentCreate(rule: 'AT_LEAST_ORDERS', months: 12, orders: 5)))->toBe([$frequent]);
    });

    it('takes "monthly buyers" as 6 orders in 6 months, and an order in another store never counts', function () {
        $monthly = Fx::customer('monthly@example.test');
        $split = Fx::customer('split@example.test');
        foreach (range(0, 5) as $n) {
            Pr::ordered($monthly, CarbonImmutable::now()->subMonths($n)->subDay());
            Pr::ordered($split, CarbonImmutable::now()->subMonths($n)->subDay(), $n < 3 ? 'sa' : 'eg');
        }
        segmentManager();

        expect(segmentMembers(segmentCreate(rule: 'AT_LEAST_ORDERS', months: 6, orders: 6)))->toBe([$monthly]);
    });

    it('keeps the order-based rules to the audience and away from the anonymized', function (string $rule, ?int $months, ?int $orders) {
        $person = Fx::customer('person@example.test');
        $firm = Fx::customer('firm@example.test', 'sa', 'company');
        $gone = Fx::customer('gone@example.test');

        foreach ([$person, $firm, $gone] as $customer) {
            Pr::ordered($customer, CarbonImmutable::now()->subMonths(8));
        }

        DB::table('access.customers')->where('id', $gone)->update(['anonymized_at' => now()]);
        segmentManager();

        expect(segmentMembers(segmentCreate(rule: $rule, months: $months, orders: $orders)))->toBe([$person])
            ->and(segmentMembers(segmentCreate(audience: 'COMPANY', rule: $rule, months: $months, orders: $orders)))->toBe([$firm]);
    })->with([
        'no order for 6 months' => ['NO_ORDER_FOR', 6, null],
        'at least 1 order in 12 months' => ['AT_LEAST_ORDERS', 12, 1],
    ]);

    it('takes the month edge as written: "more than M months old" leaves out an order exactly M months ago; "in the last M months" counts it', function () {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-18 12:00:00'));
        $edge = Fx::customer('edge@example.test');
        Pr::ordered($edge, CarbonImmutable::parse('2026-04-18 12:00:00'));
        segmentManager();

        expect(segmentMembers(segmentCreate(rule: 'NO_ORDER_FOR', months: 6)))->toBe([])
            ->and(segmentMembers(segmentCreate(rule: 'AT_LEAST_ORDERS', months: 6, orders: 1)))->toBe([$edge]);

        CarbonImmutable::setTestNow();
    });

    it('reads every page: with pages of two, five customers each rule', function () {
        app()->instance(SegmentMembership::class, new SegmentMembership(app(AccessApi::class), app(OrderFacts::class), app(Segments::class), page: 2));
        $never = [];
        $lapsed = [];

        foreach (range(1, 5) as $n) {
            $never[] = Fx::customer("never{$n}@example.test");
            $lapsed[] = $customer = Fx::customer("lapsed{$n}@example.test");
            Pr::ordered($customer, CarbonImmutable::now()->subMonths(8));
        }

        segmentManager();

        expect(segmentMembers(segmentCreate()))->toBe(sortedIds($never))
            ->and(segmentMembers(segmentCreate(rule: 'NO_ORDER_FOR', months: 6)))->toBe(sortedIds($lapsed))
            ->and(segmentMembers(segmentCreate(rule: 'AT_LEAST_ORDERS', months: 12, orders: 1)))->toBe(sortedIds($lapsed));
    });

    it('holds a companies segment\'s companies only', function () {
        $firm = Fx::customer('firm@example.test', 'sa', 'company');
        Fx::customer('person@example.test');
        segmentManager();

        expect(segmentMembers(segmentCreate(audience: 'COMPANY')))->toBe([$firm]);
    });
});

describe('picking by hand', function () {
    it('starts empty, takes customers registered in the store or who ordered there, and replaces the list', function () {
        $sara = Fx::customer('sara@example.test');
        $omar = Fx::customer('omar@example.test');
        $visitor = Fx::customer('visitor@example.test', 'eg');
        Pr::ordered($visitor, CarbonImmutable::now()->subYear());
        segmentManager();
        $segment = segmentCreate('STATIC', rule: null);

        expect(segmentMembers($segment))->toBe([]);

        app(SetSegmentMembersHandler::class)->handle(new SetSegmentMembers($segment, [strtoupper($sara), $visitor, $sara]));
        expect(segmentMembers($segment))->toBe(sortedIds([$sara, $visitor]));

        app(SetSegmentMembersHandler::class)->handle(new SetSegmentMembers($segment, [$omar]));
        expect(segmentMembers($segment))->toBe([$omar]);

        // One swapped for another is audited too: who came and went, not only how many.
        app(SetSegmentMembersHandler::class)->handle(new SetSegmentMembers($segment, [$sara]));
        $last = DB::table('platform.audit_entries')->where('action', 'promotions.segment.members_set')->orderByDesc('id')->first();

        expect(DB::table('platform.audit_entries')->where('action', 'promotions.segment.members_set')->count())->toBe(3)
            ->and(json_decode((string) $last?->changes, true))->toBe(['added' => [null, 1], 'removed' => [null, 1]]);
    });

    it('refuses a customer who may not be in the segment, and changes nothing', function (Closure $who) {
        $sara = Fx::customer('sara@example.test');
        segmentManager();
        $segment = segmentCreate('STATIC', rule: null);
        app(SetSegmentMembersHandler::class)->handle(new SetSegmentMembers($segment, [$sara]));

        expect(Pr::thrown(fn () => app(SetSegmentMembersHandler::class)->handle(new SetSegmentMembers($segment, [$sara, $who()])), InvalidPromotionAttribute::class)->attribute)->toBe('members')
            ->and(segmentMembers($segment))->toBe([$sara]);
    })->with([
        'a company in an individuals\' segment' => [fn () => Fx::customer('firm@example.test', 'sa', 'company')],
        'registered elsewhere, never ordered here' => [fn () => Fx::customer('egypt@example.test', 'eg')],
        'anonymized' => [function () {
            $gone = Fx::customer('gone@example.test');
            DB::table('access.customers')->where('id', $gone)->update(['anonymized_at' => now()]);

            return $gone;
        }],
        'nobody' => [fn () => Pr::orderId()],
    ]);

    it('refuses picking by hand for a segment a rule fills, and an id that is not one', function () {
        $sara = Fx::customer('sara@example.test');
        segmentManager();
        $dynamic = segmentCreate();
        $static = segmentCreate('STATIC', rule: null);

        expect(Pr::thrown(fn () => app(SetSegmentMembersHandler::class)->handle(new SetSegmentMembers($dynamic, [$sara])), InvalidPromotionAttribute::class)->attribute)->toBe('segment_type')
            ->and(Pr::thrown(fn () => app(SetSegmentMembersHandler::class)->handle(new SetSegmentMembers($static, ['nobody'])), InvalidPromotionAttribute::class)->attribute)->toBe('members');
    });

    it('drops an anonymized member at the next refresh, a hand-picked one included', function () {
        $sara = Fx::customer('sara@example.test');
        $omar = Fx::customer('omar@example.test');
        segmentManager();
        $segment = segmentCreate('STATIC', rule: null);
        app(SetSegmentMembersHandler::class)->handle(new SetSegmentMembers($segment, [$sara, $omar]));
        DB::table('access.customers')->where('id', $omar)->update(['anonymized_at' => now()]);

        app(RefreshSegmentHandler::class)->handle(new RefreshSegment($segment));

        expect(segmentMembers($segment))->toBe([$sara]);
    });
});

describe('creating, changing, deleting, refreshing', function () {
    it('refuses what can never be a segment, naming the field', function (array $args, string $field) {
        segmentManager();

        expect(Pr::thrown(fn () => segmentCreate(...$args), InvalidPromotionAttribute::class)->attribute)->toBe($field)
            ->and(DB::table('promotions.segments')->count())->toBe(0);
    })->with([
        'an unknown type' => [['type' => 'SMART'], 'segment_type'],
        'an unknown audience' => [['audience' => 'EVERYONE'], 'audience'],
        'a hand-picked one with a rule' => [['type' => 'STATIC'], 'rule'],
        'a rule-based one with no rule' => [['rule' => null], 'rule'],
        'an unknown rule' => [['rule' => 'BIG_SPENDERS'], 'rule'],
        'never ordered with months' => [['months' => 6], 'rule'],
        'no order for no months' => [['rule' => 'NO_ORDER_FOR'], 'rule_months'],
        'no order for 121 months' => [['rule' => 'NO_ORDER_FOR', 'months' => 121], 'rule_months'],
        'no order for with orders' => [['rule' => 'NO_ORDER_FOR', 'months' => 6, 'orders' => 2], 'rule_orders'],
        'at least 0 orders' => [['rule' => 'AT_LEAST_ORDERS', 'months' => 6, 'orders' => 0], 'rule_orders'],
        'at least 1001 orders' => [['rule' => 'AT_LEAST_ORDERS', 'months' => 6, 'orders' => 1001], 'rule_orders'],
    ]);

    it('changes the name and the rule\'s numbers, refilling the segment, never its type or audience; audited', function () {
        $lapsed = Fx::customer('lapsed@example.test');
        Pr::ordered($lapsed, CarbonImmutable::now()->subMonths(8));
        segmentManager();
        $segment = segmentCreate(rule: 'NO_ORDER_FOR', months: 12);

        expect(segmentMembers($segment))->toBe([]);

        app(ChangeSegmentHandler::class)->handle(new ChangeSegment($segment, 'شريحة', 'Lapsed', 'NO_ORDER_FOR', 6));
        $row = DB::table('promotions.segments')->where('id', $segment)->first();
        $audit = DB::table('platform.audit_entries')->where('action', 'promotions.segment.updated')->first();

        expect(segmentMembers($segment))->toBe([$lapsed])
            ->and($row?->type)->toBe('DYNAMIC')
            ->and($row?->audience)->toBe('INDIVIDUAL')
            ->and((int) $row?->rule_months)->toBe(6)
            ->and(array_keys(json_decode((string) $audit?->changes, true)))->toEqualCanonicalizing(['name_en', 'rule_months']);
    });

    it('deletes a segment and its members; a coupon assigned from it keeps its customers', function () {
        $sara = Fx::customer('sara@example.test');
        segmentManager();
        $segment = segmentCreate();
        $coupon = Pr::coupon(with: ['eligibility' => 'ASSIGNED']);
        DB::table('promotions.coupon_assignments')->insert(['coupon_id' => $coupon, 'store_id' => Fx::storeId('sa'), 'customer_id' => $sara, 'segment_id' => $segment, 'assigned_at' => now()]);

        app(DeleteSegmentHandler::class)->handle(new DeleteSegment($segment));

        expect(DB::table('promotions.segments')->where('id', $segment)->exists())->toBeFalse()
            ->and(segmentMembers($segment))->toBe([])
            ->and(DB::table('promotions.coupon_assignments')->where('coupon_id', $coupon)->where('customer_id', $sara)->value('segment_id'))->toBeNull()
            ->and(DB::table('platform.audit_entries')->where('action', 'promotions.segment.deleted')->exists())->toBeTrue();
    });

    it('gives the same members refreshed now and nightly, and keeps when a staying member was added', function () {
        $first = Fx::customer('first@example.test');
        segmentManager();
        $segment = segmentCreate();
        $addedAt = DB::table('promotions.segment_members')->where('segment_id', $segment)->value('added_at');
        $second = Fx::customer('second@example.test');
        // An hour on (the column keeps whole seconds): a staying member re-added would show.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());

        expect(Fx::asSystem(fn (): int => app(RefreshSegmentsHandler::class)->handle(new RefreshSegments)))->toBe(1);
        $nightly = segmentMembers($segment);

        segmentManager();
        app(RefreshSegmentHandler::class)->handle(new RefreshSegment($segment));

        expect($nightly)->toBe(sortedIds([$first, $second]))
            ->and(segmentMembers($segment))->toBe($nightly)
            ->and(DB::table('promotions.segment_members')->where('segment_id', $segment)->where('customer_id', $first)->value('added_at'))->toBe($addedAt)
            ->and(DB::table('promotions.segments')->where('id', $segment)->value('refreshed_at'))->not->toBeNull();
        CarbonImmutable::setTestNow();
    });

    it('goes on past a segment that fails at night, refreshing the rest', function () {
        $sara = Fx::customer('sara@example.test');
        segmentManager();
        $failing = segmentCreate();
        $fine = segmentCreate();
        DB::table('promotions.segment_members')->delete();
        $real = app(Segments::class);
        app()->instance(Segments::class, new class($real, $failing) implements Segments
        {
            public function __construct(private readonly Segments $real, private readonly string $failing) {}

            public function storeOf(string $segmentId): ?StoreId
            {
                return $this->real->storeOf($segmentId);
            }

            public function byId(StoreId $store, string $segmentId): ?Segment
            {
                return $this->real->byId($store, $segmentId);
            }

            public function lock(StoreId $store, string $segmentId): ?Segment
            {
                return $this->real->lock($store, $segmentId);
            }

            public function create(string $segmentId, StoreId $store, SegmentTerms $terms, DateTimeImmutable $at): void
            {
                $this->real->create($segmentId, $store, $terms, $at);
            }

            public function change(Segment $segment, SegmentTerms $terms, DateTimeImmutable $at): void
            {
                $this->real->change($segment, $terms, $at);
            }

            public function delete(Segment $segment): void
            {
                $this->real->delete($segment);
            }

            public function memberIds(string $segmentId): array
            {
                return $this->real->memberIds($segmentId);
            }

            public function membersAmong(string $segmentId, array $customerIds): array
            {
                return $this->real->membersAmong($segmentId, $customerIds);
            }

            public function replaceMembers(Segment $segment, array $customerIds, DateTimeImmutable $at): array
            {
                return $segment->id === $this->failing ? throw new RuntimeException('Broken on purpose.') : $this->real->replaceMembers($segment, $customerIds, $at);
            }

            public function markRefreshed(Segment $segment, DateTimeImmutable $at): void
            {
                $this->real->markRefreshed($segment, $at);
            }

            public function pageAcrossStores(?string $afterId, int $limit): array
            {
                return $this->real->pageAcrossStores($afterId, $limit);
            }

            public function membersPage(string $segmentId, ?string $after, int $limit): array
            {
                return $this->real->membersPage($segmentId, $after, $limit);
            }
        });
        app()->forgetInstance(RefreshSegmentsHandler::class);
        app()->forgetInstance(SegmentMembership::class);

        expect(Fx::asSystem(fn (): int => app(RefreshSegmentsHandler::class)->handle(new RefreshSegments)))->toBe(1)
            ->and(segmentMembers($fine))->toBe([$sara])
            ->and(segmentMembers($failing))->toBe([]);
    });

    it('runs the nightly refresh as a queued job of the system, refused to anyone else', function () {
        Fx::customer('sara@example.test');
        segmentManager();
        $segment = segmentCreate();
        DB::table('promotions.segment_members')->where('segment_id', $segment)->delete();

        expect(fn () => app(RefreshSegmentsHandler::class)->handle(new RefreshSegments))->toThrow(Unauthorized::class);

        Fx::asSystem(fn () => (new RefreshSegmentsJob)->handle(app(RefreshSegmentsHandler::class)));

        expect(segmentMembers($segment))->toHaveCount(1);
    });

    it('refreshes thousands in pages and batches, never one customer at a time', function () {
        segmentManager();
        $count = static function (int $customers): int {
            foreach (range(1, $customers) as $n) {
                Fx::customer("bulk{$customers}-{$n}@example.test");
            }

            $segment = segmentCreate();
            $queries = Cx::recordQueries();
            app(RefreshSegmentHandler::class)->handle(new RefreshSegment($segment));

            return count($queries);
        };

        expect($count(2))->toBe($count(12));
    });
});

describe('who may', function () {
    it('answers a segment of a store the staff member does not cover as not found; nobody without the job', function () {
        segmentManager(['eg']);
        $egypt = segmentCreate(storeCode: 'eg');
        segmentManager(['sa']);

        expect(fn () => app(RefreshSegmentHandler::class)->handle(new RefreshSegment($egypt)))->toThrow(SegmentNotFound::class)
            ->and(fn () => app(DeleteSegmentHandler::class)->handle(new DeleteSegment(Pr::orderId())))->toThrow(SegmentNotFound::class)
            ->and(fn () => segmentCreate(storeCode: 'eg'))->toThrow(Unauthorized::class);

        Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::COUPON_MANAGE], ['sa']));
        expect(fn () => app(RefreshSegmentHandler::class)->handle(new RefreshSegment($egypt)))->toThrow(Unauthorized::class);
    });

    it('keeps a switched-off store\'s segments for Super Admins alone', function () {
        segmentManager(['*']);
        $eg = Fx::storeId('eg');
        $egypt = segmentCreate(storeCode: 'eg');
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));

        expect(fn () => app(RefreshSegmentHandler::class)->handle(new RefreshSegment($egypt)))->toThrow(Unauthorized::class)
            ->and(fn () => app(CreateSegmentHandler::class)->handle(new CreateSegment($eg, 'ا', 'a', 'STATIC', 'INDIVIDUAL')))->toThrow(Unauthorized::class);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        app(RefreshSegmentHandler::class)->handle(new RefreshSegment($egypt));

        expect(DB::table('promotions.segments')->where('id', $egypt)->value('refreshed_at'))->not->toBeNull();
    });
});
