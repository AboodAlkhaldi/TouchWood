<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Promotions\Application\Command\CreateSegment\CreateSegment;
use Modules\Promotions\Application\Command\CreateSegment\CreateSegmentHandler;
use Modules\Promotions\Application\Command\SetSegmentMembers\SetSegmentMembers;
use Modules\Promotions\Application\Command\SetSegmentMembers\SetSegmentMembersHandler;
use Modules\Promotions\Application\PromotionsPermissions;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| Promotions' segment screens over HTTP (promotions.md §4.1 M3): Marketing › Segments, a segment's page
| with its members, and New Segment…, Edit…, Refresh Now, Delete Segment… and Save Members - what each
| page is given, who is refused, and where each refusal lands.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Queue::fake();
    // The real clock: the panel's sign-in code is checked against it.
});

/**
 * @param  list<string>  $permissions
 * @param  list<string>  $stores
 */
function segmentScreens(array $permissions = [PromotionsPermissions::SEGMENT_MANAGE], array $stores = ['sa']): AdminBrowser
{
    $staffId = Fx::staffWith($permissions, $stores);
    $browser = new AdminBrowser('10.9.3.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/**
 * @return array<string, mixed>
 */
function segmentScreensProps(AdminBrowser $browser, string $uri, string $component): array
{
    $props = [];

    $browser->get($uri)->assertOk()->assertInertia(function (AssertableInertia $page) use ($component, &$props) {
        $page->component($component);
        $props = $page->toArray()['props'];
    });

    return $props;
}

/**
 * @param  TestResponse<Response>  $response
 */
function segmentScreensFieldError(TestResponse $response, string $field): ?string
{
    $errors = AdminBrowser::flashed($response, 'errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->first($field) === '' ? null : $errors->first($field);
    }

    $message = is_array($errors) ? ($errors['default']['messages'][$field][0] ?? null) : null;

    return is_string($message) ? $message : null;
}

/** A segment made as the system: by rule unless `static`. */
function segmentScreensSegment(string $nameEn = 'Never ordered', bool $static = false, string $storeCode = 'sa'): string
{
    return Fx::asSystem(fn (): string => app(CreateSegmentHandler::class)->handle(new CreateSegment(
        Fx::storeId($storeCode), 'شريحة', $nameEn, $static ? 'STATIC' : 'DYNAMIC', 'INDIVIDUAL', $static ? null : 'NO_ORDERS_EVER',
    )));
}

function segmentScreensCustomer(string $email, string $first): string
{
    $id = Fx::customer($email);
    DB::table('access.customers')->where('id', $id)->update(['first_name' => $first, 'last_name' => 'Screen']);

    return $id;
}

/** @return list<string> */
function segmentScreensMembers(string $segmentId): array
{
    return array_values(array_map(strval(...), DB::table('promotions.segment_members')->where('segment_id', $segmentId)->orderBy('customer_id')->pluck('customer_id')->all()));
}

function segmentScreensOwnQueries(AdminBrowser $browser, string $uri): int
{
    $browser->get($uri)->assertOk();
    /** @var ArrayObject<int, string> $own */
    $own = new ArrayObject;

    DB::listen(function (QueryExecuted $query) use ($own): void {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (str_contains(str_replace(chr(92), '/', $frame['file'] ?? ''), '/src/Modules/Promotions/')) {
                $own[] = $query->sql;

                return;
            }
        }
    });

    $browser->get($uri)->assertOk();

    return count($own);
}

describe('M3 · the list', function () {
    it('lists the store\'s segments with their rule in words and what a reader may do', function () {
        segmentScreensSegment('Never ordered');
        segmentScreensSegment('Picked', static: true);
        segmentScreensSegment('Egypt only', storeCode: 'eg');

        $props = segmentScreensProps(segmentScreens([PromotionsPermissions::VIEW]), '/admin/segments', 'Promotions/Admin/Segments/Index');

        expect(array_column($props['segments'], 'name'))->toEqualCanonicalizing(['Never ordered', 'Picked'])
            ->and(array_column($props['segments'], 'rule', 'name'))->toEqual(['Never ordered' => 'NO_ORDERS_EVER', 'Picked' => null])
            ->and(array_column($props['segments'], 'type', 'name'))->toEqual(['Never ordered' => 'DYNAMIC', 'Picked' => 'STATIC'])
            ->and([$props['mayManage'], $props['storeCode']])->toBe([false, 'sa']);
    });

    it('refuses a reader with no Marketing job', function () {
        segmentScreens(['access.customer.view'])->get('/admin/segments')->assertForbidden();
    });
});

describe('M3 · changes', function () {
    it('creates a rule segment from the dialog, its numbers in any digits, and opens its page', function () {
        $response = segmentScreens()->post('/admin/segments', [
            'store' => 'sa', 'name_ar' => 'نائمون', 'name_en' => 'Sleeping', 'segment_type' => 'DYNAMIC', 'audience' => 'INDIVIDUAL',
            'rule' => 'NO_ORDER_FOR', 'rule_months' => '٦', 'rule_orders' => '',
        ]);

        $row = DB::table('promotions.segments')->where('name_en', 'Sleeping')->first();
        assert($row instanceof stdClass);
        $response->assertRedirect("/admin/segments/{$row->id}");

        expect([$row->rule, $row->rule_months, AdminBrowser::flashed($response, 'status')])->toBe(['NO_ORDER_FOR', 6, 'Segment created.']);
    });

    it('says a refused number beside its own box', function () {
        $response = segmentScreens()->post('/admin/segments', [
            'store' => 'sa', 'name_ar' => 'نائمون', 'name_en' => 'Sleeping', 'segment_type' => 'DYNAMIC', 'audience' => 'INDIVIDUAL',
            'rule' => 'NO_ORDER_FOR', 'rule_months' => '500',
        ]);

        expect(segmentScreensFieldError($response, 'rule_months'))->not->toBeNull()
            ->and(DB::table('promotions.segments')->count())->toBe(0);
    });

    it('edits the names, refreshes with the count in the toast, and deletes back to the list', function () {
        $segment = segmentScreensSegment();
        segmentScreensCustomer('one@example.test', 'One');
        $browser = segmentScreens();

        $browser->post("/admin/segments/{$segment}", ['name_ar' => 'جديد', 'name_en' => 'Renamed', 'rule' => 'NO_ORDERS_EVER']);
        $refreshed = $browser->post("/admin/segments/{$segment}/refresh");

        expect([DB::table('promotions.segments')->where('id', $segment)->value('name_en'), AdminBrowser::flashed($refreshed, 'status')])->toBe(['Renamed', 'Members updated: 1.']);

        // Back to the list of the segment's own store.
        $browser->post("/admin/segments/{$segment}/delete")->assertRedirect('/admin/segments?store=sa');
        expect(DB::table('promotions.segments')->where('id', $segment)->exists())->toBeFalse();
    });

    it('saves the members picked by hand: the adds and removes merged into the whole list', function () {
        $segment = segmentScreensSegment('Picked', static: true);
        $keep = segmentScreensCustomer('keep@example.test', 'Keep');
        $drop = segmentScreensCustomer('drop@example.test', 'Drop');
        $new = segmentScreensCustomer('new@example.test', 'New');
        Fx::asSystem(fn () => app(SetSegmentMembersHandler::class)->handle(new SetSegmentMembers($segment, [$keep, $drop])));

        $saved = segmentScreens()->post("/admin/segments/{$segment}/members", ['add' => [$new, 'not-an-id'], 'remove' => [$drop]]);

        $expected = [$keep, $new];
        sort($expected);
        expect([AdminBrowser::flashed($saved, 'status'), segmentScreensMembers($segment)])->toBe(['Members saved.', $expected]);
    });

    it('finds customers for a hand-picked segment by name, marking who is in already', function () {
        $segment = segmentScreensSegment('Picked', static: true);
        $in = segmentScreensCustomer('nadia@example.test', 'Nadia');
        segmentScreensCustomer('nader@example.test', 'Nader');
        Fx::asSystem(fn () => app(SetSegmentMembersHandler::class)->handle(new SetSegmentMembers($segment, [$in])));

        $props = segmentScreensProps(segmentScreens(), "/admin/segments/{$segment}?search=Nad", 'Promotions/Admin/Segments/Show');
        $found = array_map(static fn (array $row): array => [$row['customer']['name'], $row['member']], $props['candidates']);

        // By name (owner's Q9), each marked when already in.
        expect([$found, $props['search'], array_column(array_column($props['members'], 'customer'), 'id')])->toBe([[['Nader Screen', false], ['Nadia Screen', true]], 'Nad', [$in]]);
    });

    it('searches nothing for a reader who may only view, and refuses their changes', function () {
        $segment = segmentScreensSegment('Picked', static: true);
        segmentScreensCustomer('nadia@example.test', 'Nadia');
        $viewer = segmentScreens([PromotionsPermissions::VIEW]);

        $props = segmentScreensProps($viewer, "/admin/segments/{$segment}?search=Nad", 'Promotions/Admin/Segments/Show');
        $refused = $viewer->post("/admin/segments/{$segment}", ['name_ar' => 'x', 'name_en' => 'Hijacked']);

        expect([$props['candidates'], $props['mayManage'], $refused->getStatusCode()])->toBe([[], false, 302])
            ->and(DB::table('promotions.segments')->where('id', $segment)->value('name_en'))->toBe('Picked');
    });
});

it('reads each segment page in a fixed few queries, the same for one member as for six', function () {
    $segment = segmentScreensSegment('Picked', static: true);
    $members = [segmentScreensCustomer('m0@example.test', 'M0')];
    Fx::asSystem(fn () => app(SetSegmentMembersHandler::class)->handle(new SetSegmentMembers($segment, $members)));
    $pages = ['/admin/segments', "/admin/segments/{$segment}"];
    $counts = static fn (): array => array_map(static fn (string $uri): int => segmentScreensOwnQueries(segmentScreens(), $uri), $pages);
    $one = $counts();

    foreach (range(1, 5) as $n) {
        $members[] = segmentScreensCustomer("m{$n}@example.test", "M{$n}");
        segmentScreensSegment("Rule {$n}");
    }
    Fx::asSystem(fn () => app(SetSegmentMembersHandler::class)->handle(new SetSegmentMembers($segment, $members)));

    expect($counts())->toBe($one)
        ->and($one)->toBe([3, 7]);
});
