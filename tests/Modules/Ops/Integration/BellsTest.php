<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Ops\Application\Command\MarkAllInboxRead\MarkAllInboxRead;
use Modules\Ops\Application\Command\MarkAllInboxRead\MarkAllInboxReadHandler;
use Modules\Ops\Application\Command\MarkInboxEntryRead\MarkInboxEntryRead;
use Modules\Ops\Application\Command\MarkInboxEntryRead\MarkInboxEntryReadHandler;
use Modules\Ops\Application\Inbox\InboxPage;
use Modules\Ops\Application\Inbox\InboxReaders;
use Modules\Ops\Application\Inbox\InboxRow;
use Modules\Ops\Application\Query\CountUnread\CountUnread;
use Modules\Ops\Application\Query\CountUnread\CountUnreadHandler;
use Modules\Ops\Application\Query\ViewInbox\ViewInbox;
use Modules\Ops\Application\Query\ViewInbox\ViewInboxHandler;
use Modules\Ops\Domain\Exception\InboxEntryNotFound;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Public\Enums\MessageKind;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Shared\Application\Actor;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Ops\Support\OpsFixtures as Ox;

use function Pest\Laravel\seed;

/*
| The bells (ops.md §1.2, §3): own only. A customer's in the shop's store, a staff member's over the
| stores they may view; newest first, a page at a time; the unread count; one marked read, all marked
| read - never another person's, never another store's.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

function bellsPage(string $storeCode = 'sa', string $locale = 'en', ?string $after = null): InboxPage
{
    return Fx::inStoreCode($storeCode, fn (): InboxPage => app(ViewInboxHandler::class)->handle(new ViewInbox($locale, $after)));
}

/**
 * @return list<string>
 */
function bellsIds(InboxPage $page): array
{
    return array_map(static fn (InboxRow $row): string => $row->id, $page->rows);
}

describe('a customer\'s bell', function () {
    it('shows only their own entries of the shop\'s store, newest first, in their language', function () {
        $sara = Fx::customer();
        $omar = Fx::customer('omar@example.test');
        $older = Ox::customerEntry($sara, 'sa', CarbonImmutable::now()->subHour());
        $newer = Ox::customerEntry($sara, 'sa');
        Ox::customerEntry($sara, 'ae');
        Ox::customerEntry($omar, 'sa');

        Fx::actAsCustomer($sara);

        $page = bellsPage();
        expect(bellsIds($page))->toBe([$newer, $older])
            ->and($page->unread)->toBe(2)
            ->and($page->next)->toBeNull()
            ->and($page->rows[0]->title)->toBe('Order SA-0001 approved')
            ->and($page->rows[0]->kind)->toBe(MessageKind::OrderApproved)
            ->and($page->rows[0]->read)->toBeFalse()
            ->and(bellsPage('sa', 'ar')->rows[0]->title)->toBe('اعتُمد الطلب SA-0001')
            // The other store's bell is that store's alone.
            ->and(bellsIds(bellsPage('ae')))->toHaveCount(1);
    });

    it('pages by the cursor the page before gave, and ends where the list ends', function () {
        $sara = Fx::customer();
        $ids = [];

        for ($i = 0; $i < InboxReaders::PAGE + 3; $i++) {
            $ids[] = Ox::customerEntry($sara, 'sa', CarbonImmutable::now()->subMinutes(100 - $i));
        }

        Fx::actAsCustomer($sara);
        $first = bellsPage();
        $second = bellsPage(after: $first->next);

        expect(bellsIds($first))->toBe(array_slice(array_reverse($ids), 0, InboxReaders::PAGE))
            ->and($first->next)->toBe($ids[3])
            ->and(bellsIds($second))->toBe(array_reverse(array_slice($ids, 0, 3)))
            ->and($second->next)->toBeNull()
            ->and($second->unread)->toBe(InboxReaders::PAGE + 3)
            ->and(fn () => bellsPage(after: 'not-an-id'))->toThrow(InvalidOpsInput::class);
    });

    it('takes no cursor of another person\'s: the list simply ends', function () {
        $sara = Fx::customer();
        Ox::customerEntry($sara, 'sa');
        $omars = Ox::customerEntry(Fx::customer('omar@example.test'), 'sa');

        Fx::actAsCustomer($sara);

        expect(bellsIds(bellsPage(after: $omars)))->toBe([]);
    });

    it('marks one read, then all, never another person\'s or another store\'s', function () {
        $sara = Fx::customer();
        $omar = Fx::customer('omar@example.test');
        $first = Ox::customerEntry($sara, 'sa');
        Ox::customerEntry($sara, 'sa');
        $elsewhere = Ox::customerEntry($sara, 'ae');
        $omars = Ox::customerEntry($omar, 'sa');

        Fx::actAsCustomer($sara);
        $mark = fn (string $id): int => Fx::inStoreCode('sa', fn (): int => app(MarkInboxEntryReadHandler::class)->handle(new MarkInboxEntryRead($id)));

        expect($mark($first))->toBe(1)
            // Again: it stays read, the number as it was.
            ->and($mark($first))->toBe(1)
            ->and(fn () => $mark($omars))->toThrow(InboxEntryNotFound::class)
            ->and(fn () => $mark($elsewhere))->toThrow(InboxEntryNotFound::class)
            ->and(fn () => $mark('nothing'))->toThrow(InboxEntryNotFound::class);

        expect(Fx::inStoreCode('sa', fn (): int => app(MarkAllInboxReadHandler::class)->handle(new MarkAllInboxRead)))->toBe(1)
            ->and(Fx::inStoreCode('sa', fn (): int => app(CountUnreadHandler::class)->handle(new CountUnread)))->toBe(0)
            ->and(DB::table('ops.inbox_entries')->whereNull('read_at')->orderBy('id')->pluck('id')->all())->toEqualCanonicalizing([$elsewhere, $omars]);
    });
});

describe('a staff member\'s bell', function () {
    it('shows their own entries over the stores that are on, an off store\'s only to a Super Admin', function () {
        $staff = Fx::staffWith(['ops.report.view'], ['sa']);
        $other = Fx::staffWith(['ops.report.view'], ['sa']);
        $super = Fx::staff(superAdmin: true);
        $sa = Ox::staffEntry($staff, 'sa');
        $eg = Ox::staffEntry($staff, 'eg', CarbonImmutable::now()->subMinute());
        Ox::staffEntry($other, 'sa');
        $superEg = Ox::staffEntry($super, 'eg');

        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));

        Fx::actAsStaff($staff);
        $page = app(ViewInboxHandler::class)->handle(new ViewInbox('en'));
        expect(bellsIds($page))->toBe([$sa])
            ->and($page->unread)->toBe(1)
            ->and(fn () => app(MarkInboxEntryReadHandler::class)->handle(new MarkInboxEntryRead($eg)))->toThrow(InboxEntryNotFound::class);

        Fx::actAsStaff($super);
        expect(bellsIds(app(ViewInboxHandler::class)->handle(new ViewInbox('en'))))->toBe([$superEg])
            ->and(app(MarkAllInboxReadHandler::class)->handle(new MarkAllInboxRead))->toBe(1);
    });

    it('leaves out a store that is on but not theirs, and follows a change of their stores (access.md amendment 88)', function () {
        $staff = Fx::staffWith(['ops.report.view'], ['sa']);
        $sa = Ox::staffEntry($staff, 'sa');
        $ae = Ox::staffEntry($staff, 'ae', CarbonImmutable::now()->subMinute());

        Fx::actAsStaff($staff);
        expect(bellsIds(app(ViewInboxHandler::class)->handle(new ViewInbox('en'))))->toBe([$sa])
            ->and(app(CountUnreadHandler::class)->handle(new CountUnread))->toBe(1)
            ->and(fn () => app(MarkInboxEntryReadHandler::class)->handle(new MarkInboxEntryRead($ae)))->toThrow(InboxEntryNotFound::class)
            ->and(app(MarkAllInboxReadHandler::class)->handle(new MarkAllInboxRead))->toBe(1)
            ->and(DB::table('ops.inbox_entries')->where('id', $ae)->value('read_at'))->toBeNull();

        Fx::assign($staff, Fx::roleOf($staff), ['ae']);
        Fx::actAsStaff($staff);

        expect(bellsIds(app(ViewInboxHandler::class)->handle(new ViewInbox('en'))))->toBe([$ae])
            ->and(app(CountUnreadHandler::class)->handle(new CountUnread))->toBe(1);
    });
});

it('has no bell for a guest or the system', function () {
    Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));
    expect(fn () => bellsPage())->toThrow(Unauthorized::class);

    expect(fn () => Fx::asSystem(fn () => app(CountUnreadHandler::class)->handle(new CountUnread)))->toThrow(Unauthorized::class);
});
