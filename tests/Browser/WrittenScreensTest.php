<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Promotions\Application\Command\CreateSegment\CreateSegment;
use Modules\Promotions\Application\Command\CreateSegment\CreateSegmentHandler;
use Modules\Promotions\Application\Command\SetSegmentMembers\SetSegmentMembers;
use Modules\Promotions\Application\Command\SetSegmentMembers\SetSegmentMembersHandler;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\WrittenFixtures as Wx;

use function Pest\Laravel\seed;

/*
| O3-O6, Customer Messages and Offer Notices in a real browser (ops.md §4.15): a message to one goes at once, to many it
| asks with the count; the pickers; the read-back; Refresh while something sends; a notice written, changed, cancelled,
| started meanwhile; its link cut down to the page; a deleted segment; both languages, and a phone.
|
| Deliberately no RefreshDatabase, as the other browser tests. Everything is written in `ae`, where few runs leave customers,
| since a message to a whole store is sent to each of them, and each run's rows have their own names.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/**
 * @param  list<string>  $permissions
 */
function writtenBrowser(array $permissions, string $locale = 'en'): mixed
{
    return writtenBrowserIn(Fx::staffWith($permissions, ['ae'], RoleLevel::Admin), $locale);
}

function writtenBrowserIn(string $staffId, string $locale = 'en'): mixed
{
    DB::table('access.staff_users')->where('id', $staffId)->update(['locale' => $locale]);

    $page = visit('/admin/sign-in')
        ->type('#email', (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'))
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]');

    expect(browserSoon($page, "window.location.pathname === '/admin/sign-in/code' && document.querySelector('input[autocomplete=\"one-time-code\"]') !== null"))->toBeTrue();

    $page->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(browserSoon($page, "window.location.pathname === '/admin'"))->toBeTrue();

    return $page;
}

function writtenBrowserRun(): string
{
    return substr((string) hrtime(true), -10);
}

function writtenBrowserBoxes(mixed $page, string $title): void
{
    $page->type('#title_ar', "عنوان {$title}")
        ->type('#title_en', $title)
        ->type('#body_ar', 'نص الرسالة')
        ->type('#body_en', 'The text');
}

/**
 * @param  list<string>  $members
 */
function writtenBrowserSegment(string $name, array $members = []): string
{
    return Fx::asSystem(static function () use ($name, $members): string {
        $id = app(CreateSegmentHandler::class)->handle(new CreateSegment(Fx::storeId('ae'), "شريحة {$name}", $name, 'STATIC', 'INDIVIDUAL'));
        app(SetSegmentMembersHandler::class)->handle(new SetSegmentMembers($id, $members));

        return $id;
    });
}

/**
 * A notice written straight in its table, a day from now in the store's clock.
 *
 * @param  array<string, mixed>  $with
 */
function writtenBrowserNotice(string $title, array $with = []): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('ops.notices')->insert([
        'id' => $id, 'store_id' => Fx::storeId('ae'), 'title_ar' => "ع {$title}", 'title_en' => $title, 'body_ar' => 'ن', 'body_en' => 'B', 'by_bell' => true, 'by_email' => false,
        'status' => 'SCHEDULED', 'send_at' => now()->addDays(2), 'created_by' => Fx::staff(superAdmin: true), 'created_at' => now(), 'updated_at' => now(), ...$with,
    ]);

    return $id;
}

function writtenBrowserFits(mixed $page): void
{
    expect(browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
}

it('sends to one customer at once, after the search asks for three characters, and reads it back', function () {
    $run = writtenBrowserRun();
    $email = "written{$run}@example.test";
    Wx::agreeing($email, 'ae');
    $page = writtenBrowser(['ops.message.send_one', 'ops.message.send_many', 'access.customer.view']);

    $page->navigate('/admin/messages/new?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"who-ONE\"]') !== null"))->toBeTrue();

    $page->click('[data-test="customer"]')->type('[data-test="customer-search"]', 'wr');
    expect(browserSoon($page, "document.querySelector('[data-test=\"customer-more\"]') !== null"))->toBeTrue();

    $page->type('[data-test="customer-search"]', $email);
    expect(browserSoon($page, "document.querySelector('[data-test^=\"customer-0\"], [data-test^=\"customer-1\"], [data-test^=\"customer-a\"], [data-test^=\"customer-b\"]') !== null"))->toBeTrue();
    $page->script("(() => { document.querySelector('[cmdk-item]').click(); })()");
    expect(browserSoon($page, "document.querySelector('[data-test=\"chosen-customer\"]')?.textContent.includes('{$email}')"))->toBeTrue();

    writtenBrowserBoxes($page, "One {$run}");
    expect((string) $page->script("document.querySelector('[data-test=\"email-note\"]').textContent"))->toBe('It goes to their email whatever they chose about offers.');

    $page->click('[data-test="send"]');

    // No question: it went, and the list says so.
    expect(browserSoon($page, "window.location.pathname === '/admin/messages' && document.body.textContent.includes('Message on its way.')"))->toBeTrue();
    $id = (string) DB::table('ops.staff_messages')->where('title_en', "One {$run}")->value('id');
    expect($id)->not->toBe('');

    $page->click("[data-test=\"message-{$id}\"] [data-test=\"open\"]");
    expect(browserSoon($page, "document.querySelector('[data-test=\"read-back\"]')?.textContent.includes('عنوان One {$run}')"))->toBeTrue()
        ->and((string) $page->script("document.querySelector('[data-test=\"read-back\"]').textContent"))->toContain('The text')->toContain('نص الرسالة');
    $page->assertNoJavaScriptErrors();

    $page->resize(375, 812);
    writtenBrowserFits($page);
});

it('saves digits typed in Arabic as 0-9', function () {
    $run = writtenBrowserRun();
    Wx::agreeing("digits{$run}@example.test", 'ae');
    $page = writtenBrowser(['ops.notice.manage']);

    $page->navigate('/admin/notices/new?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('#title_ar') !== null"))->toBeTrue();
    writtenBrowserBoxes($page, "Digits {$run}");
    $page->type('#body_ar', 'خصم ٢٠٪');
    expect($page->script("document.querySelector('#body_ar').value"))->toBe('خصم 20٪');
    $page->click('[data-test="send"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-send\"]') !== null"))->toBeTrue();
    $page->click('[data-test="confirm-send"]');
    expect(browserSoon($page, "window.location.pathname === '/admin/notices'"))->toBeTrue()
        ->and(DB::table('ops.notices')->where('title_en', "Digits {$run}")->value('body_ar'))->toBe('خصم 20٪');
});

it('says when a store has nobody to send to, and offers no Send', function () {
    $page = writtenBrowser(['ops.message.send_many']);

    $page->navigate('/admin/messages/new?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('#title_ar') !== null"))->toBeTrue();
    writtenBrowserBoxes($page, 'Nobody');
    // The one way to a store with no customers in a database the runs share: the count's answer.
    $page->script("(() => { const real = window.fetch; window.fetch = (input, init) => String(input).includes('/admin/ops/audience') ? Promise.resolve(new Response(JSON.stringify({ count: 0 }), { status: 200, headers: { 'Content-Type': 'application/json' } })) : real(input, init); })()");
    $page->click('[data-test="send"]');

    expect(browserSoon($page, "document.querySelector('[data-test=\"nobody\"]') !== null"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"confirm-send\"]') === null"))->toBeTrue();
});

it('forgets the customer and the segment when the store changes, and sends to the audience shown, not the customer chosen before', function () {
    $run = writtenBrowserRun();
    $email = "change{$run}@example.test";
    Wx::agreeing($email, 'ae');
    $staffId = Fx::staffWith(['ops.message.send_one', 'ops.message.send_many', 'access.customer.view'], ['ae', 'sa'], RoleLevel::Admin);
    $page = writtenBrowserIn($staffId);

    $page->navigate('/admin/messages/new?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"customer\"]') !== null"))->toBeTrue();
    $page->click('[data-test="customer"]')->type('[data-test="customer-search"]', $email);
    expect(browserSoon($page, "document.querySelector('[cmdk-item]') !== null"))->toBeTrue();
    $page->script("(() => { document.querySelector('[cmdk-item]').click(); })()");
    expect(browserSoon($page, "document.querySelector('[data-test=\"chosen-customer\"]') !== null"))->toBeTrue();

    // Another store: the customer is that store's no more.
    $page->select('[data-test="store"]', 'sa');
    expect(browserSoon($page, "document.querySelector('[data-test=\"chosen-customer\"]') === null && document.querySelector('[data-test=\"customer\"]') !== null"))->toBeTrue();
    $page->select('[data-test="store"]', 'ae');
    expect(browserSoon($page, "document.querySelector('[data-test=\"customer\"]') !== null"))->toBeTrue();

    // A customer chosen, then the whole store: the customer isn't sent, or the server would refuse it.
    $page->click('[data-test="customer"]')->type('[data-test="customer-search"]', $email);
    expect(browserSoon($page, "document.querySelector('[cmdk-item]') !== null"))->toBeTrue();
    $page->script("(() => { document.querySelector('[cmdk-item]').click(); })()");
    expect(browserSoon($page, "document.querySelector('[data-test=\"chosen-customer\"]') !== null"))->toBeTrue();
    $page->click('[data-test="who-STORE"]');
    writtenBrowserBoxes($page, "Switched {$run}");
    $page->click('[data-test="send"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-send\"]') !== null"))->toBeTrue();
    $page->click('[data-test="confirm-send"]');
    expect(browserSoon($page, "window.location.pathname === '/admin/messages'"))->toBeTrue();

    $sent = DB::table('ops.staff_messages')->where('title_en', "Switched {$run}")->first();
    expect($sent?->audience)->toBe('STORE')
        ->and($sent?->customer_id)->toBeNull();
    $page->assertNoJavaScriptErrors();
});

it('empties the picker and says so when a segment is deleted before the send', function () {
    $run = writtenBrowserRun();
    $sara = Wx::agreeing("gone{$run}@example.test", 'ae');
    $segment = writtenBrowserSegment("Going {$run}", [$sara]);
    $page = writtenBrowser(['ops.message.send_many']);

    $page->navigate('/admin/messages/new?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"who-SEGMENT\"]') !== null"))->toBeTrue();
    $page->click('[data-test="who-SEGMENT"]')->click('[data-test="segment"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"segment-{$segment}\"]') !== null"))->toBeTrue();
    $page->click("[data-test=\"segment-{$segment}\"]");
    writtenBrowserBoxes($page, "Going {$run}");
    $page->click('[data-test="send"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-send\"]') !== null"))->toBeTrue();

    DB::table('promotions.segment_members')->where('segment_id', $segment)->delete();
    DB::table('promotions.segments')->where('id', $segment)->delete();
    $page->click('[data-test="confirm-send"]');

    expect(browserSoon($page, "document.querySelector('[role=\"dialog\"]') === null && (document.querySelector('[data-test=\"segment\"]')?.textContent ?? '').includes('Choose a segment')"))->toBeTrue()
        ->and(browserSoon($page, "[...document.querySelectorAll('[data-sonner-toast]')].length > 0", 5))->toBeTrue()
        ->and($page->script('location.pathname'))->toBe('/admin/messages/new')
        ->and($page->script("document.querySelector('#title_en').value"))->toBe("Going {$run}")
        ->and(DB::table('ops.staff_messages')->where('title_en', "Going {$run}")->count())->toBe(0);
});

it('asks before a message to the whole store, in the wording of how it goes', function () {
    $run = writtenBrowserRun();
    Wx::agreeing("store{$run}@example.test", 'ae');
    $page = writtenBrowser(['ops.message.send_one', 'ops.message.send_many', 'access.customer.view']);

    $page->navigate('/admin/messages/new?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"who-STORE\"]') !== null"))->toBeTrue();
    $page->click('[data-test="who-STORE"]');
    writtenBrowserBoxes($page, "Store {$run}");
    expect((string) $page->script("document.querySelector('[data-test=\"email-note\"]').textContent"))->toBe('Email goes only to those who agreed to offers. The bell reaches everyone.');

    // Email alone says who it reaches.
    $page->click('[data-test="by-bell"]')->click('[data-test="send"]');
    expect(browserSoon($page, "/^Send by email to (those of )?the \\d+ customers? of .+/.test(document.querySelector('[role=\"dialog\"] h2')?.textContent ?? '')"))->toBeTrue();
    $page->click('[data-test="modal-cancel"]');
    expect(browserSoon($page, "document.querySelector('[role=\"dialog\"]') === null"))->toBeTrue();
    expect(DB::table('ops.staff_messages')->where('title_en', "Store {$run}")->count())->toBe(0);

    // Bell ticked: the owner's other wording.
    $page->click('[data-test="by-bell"]')->click('[data-test="send"]');
    expect(browserSoon($page, "/^Send to \\d+ customers? of .+\\?$/.test(document.querySelector('[role=\"dialog\"] h2')?.textContent ?? '')"))->toBeTrue();
    $page->click('[data-test="confirm-send"]');

    expect(browserSoon($page, "window.location.pathname === '/admin/messages' && document.body.textContent.includes('Message on its way.')"))->toBeTrue()
        ->and(DB::table('ops.staff_messages')->where('title_en', "Store {$run}")->value('audience'))->toBe('STORE');
    $page->assertNoJavaScriptErrors();
});

it('lists a segment with no members but offers it to nobody, and asks naming the one chosen', function () {
    $run = writtenBrowserRun();
    $sara = Wx::agreeing("segment{$run}@example.test", 'ae');
    $empty = writtenBrowserSegment("Empty {$run}");
    $held = writtenBrowserSegment("Held {$run}", [$sara]);
    $page = writtenBrowser(['ops.message.send_many']);

    $page->navigate('/admin/messages/new?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"who-SEGMENT\"]') !== null"))->toBeTrue();
    $page->click('[data-test="who-SEGMENT"]')->click('[data-test="segment"]');

    expect(browserSoon($page, "document.querySelector('[data-test=\"segment-{$held}\"]') !== null"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"segment-{$empty}\"]').getAttribute('aria-disabled')"))->toBe('true')
        ->and((string) $page->script("document.querySelector('[data-test=\"segment-{$empty}\"]').textContent"))->toContain('No members');

    $page->click("[data-test=\"segment-{$held}\"]");
    writtenBrowserBoxes($page, "Segment {$run}");
    $page->click('[data-test="send"]');
    expect(browserSoon($page, "document.querySelector('[role=\"dialog\"] h2')?.textContent.replace(/[\u2068\u2069]/g, '') === 'Send to Held {$run} (1 member)?'"))->toBeTrue();
    $page->click('[data-test="confirm-send"]');

    expect(browserSoon($page, "window.location.pathname === '/admin/messages' && document.querySelector('[data-test=\"to\"]')?.textContent.includes('Held {$run}')"))->toBeTrue()
        ->and(DB::table('ops.staff_messages')->where('title_en', "Segment {$run}")->value('segment_id'))->toBe($held);
    $page->assertNoJavaScriptErrors();
});

it('shows Refresh only while something sends, and a Refresh after Show More shows what the server says now', function () {
    $staff = Fx::staff(superAdmin: true);
    $base = ['store_id' => Fx::storeId('ae'), 'sender_id' => $staff, 'audience' => 'STORE', 'title_ar' => 'ع', 'title_en' => 'T', 'body_ar' => 'ن', 'body_en' => 'B', 'by_bell' => true, 'by_email' => false];
    $newest = '';

    foreach (range(1, 25) as $i) {
        $id = strtolower((string) Str::ulid());
        DB::table('ops.staff_messages')->insert([...$base, 'id' => $id, 'status' => 'SENT', 'finished_at' => now(), 'created_at' => now()]);
        $newest = $id;
    }

    $page = writtenBrowser(['ops.message.send_many']);
    $page->navigate('/admin/messages?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"message-{$newest}\"]') !== null"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"refresh\"]') === null"))->toBeTrue();

    DB::table('ops.staff_messages')->where('id', $newest)->update(['status' => 'SENDING', 'finished_at' => null]);
    $page->navigate('/admin/messages?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"refresh\"]') !== null"))->toBeTrue();

    $rows = "document.querySelectorAll('[data-test^=\"message-\"]')";
    $before = (int) $page->script("{$rows}.length");
    $page->click('[data-test="more"]');
    expect(browserSoon($page, "{$rows}.length > {$before}"))->toBeTrue();

    DB::table('ops.staff_messages')->where('id', $newest)->update(['status' => 'SENT', 'finished_at' => now()]);
    $page->click('[data-test="refresh"]');

    expect(browserSoon($page, "document.querySelector('[data-test=\"message-{$newest}\"] [data-test=\"status\"]')?.textContent === 'Sent'", 5))->toBeTrue('Refresh left the old status')
        ->and(browserSoon($page, "document.querySelector('[data-test=\"refresh\"]') === null", 5))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('writes a notice now, cutting a pasted address down to its page and saying when one is another store\'s or another site\'s', function () {
    $run = writtenBrowserRun();
    Wx::agreeing("notice{$run}@example.test", 'ae');
    $page = writtenBrowser(['ops.notice.manage']);

    $page->navigate('/admin/notices/new?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('#title_ar') !== null"))->toBeTrue();
    writtenBrowserBoxes($page, "Notice {$run}");

    $page->type('#link', 'https://elsewhere.example/ae/en/x');
    expect(browserSoon($page, "document.body.textContent.includes('This address is on another site.')"))->toBeTrue();

    $origin = (string) $page->script('window.location.origin');
    $page->type('#link', "{$origin}/sa/en/categories/chairs");
    expect(browserSoon($page, "document.body.textContent.includes('This address belongs to another store.')"))->toBeTrue();

    $page->type('#link', "{$origin}/ae/ar/categories/chairs");
    expect(browserSoon($page, "document.body.textContent.includes('It opens: categories/chairs')"))->toBeTrue();

    // Now is ticked on New, and asks first.
    expect((string) $page->script("document.querySelector('[data-test=\"send\"]').textContent"))->toContain('Send Notice');
    $page->click('[data-test="send"]');
    expect(browserSoon($page, "/^Send to \\d+ customers? of .+\\?$/.test(document.querySelector('[role=\"dialog\"] h2')?.textContent ?? '')"))->toBeTrue();
    $page->click('[data-test="confirm-send"]');

    expect(browserSoon($page, "window.location.pathname === '/admin/notices' && document.body.textContent.includes('Notice on its way.')"))->toBeTrue();
    expect(DB::table('ops.notices')->where('title_en', "Notice {$run}")->value('link'))->toBe('categories/chairs');
    $page->assertNoJavaScriptErrors();
});

it('edits a waiting notice on its saved time without asking, and cancels one from its menu', function () {
    $run = writtenBrowserRun();
    $edited = writtenBrowserNotice("Edit {$run}");
    $cancelled = writtenBrowserNotice("Cancel {$run}");
    $page = writtenBrowser(['ops.notice.manage']);

    $page->navigate("/admin/notices/{$edited}/edit", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('#when-time')?.value.length === 5"))->toBeTrue()
        ->and((string) $page->script("document.querySelector('[data-test=\"send\"]').textContent"))->toContain('Save Changes');

    $page->type('#title_en', "Edited {$run}")->click('[data-test="send"]');

    // No question: a scheduled save says when it goes.
    expect(browserSoon($page, "window.location.pathname === '/admin/notices' && document.body.textContent.includes('Notice scheduled.')"))->toBeTrue()
        ->and(DB::table('ops.notices')->where('id', $edited)->value('title_en'))->toBe("Edited {$run}")
        ->and(DB::table('ops.notices')->where('id', $edited)->value('status'))->toBe('SCHEDULED');

    $page->script("(() => { document.querySelector('[data-test=\"more-{$cancelled}\"]').scrollIntoView(); })()");
    $page->click("[data-test=\"more-{$cancelled}\"]")->click('[data-test="cancel-notice"]');
    expect(browserSoon($page, "document.body.textContent.includes('Cancel this notice?')"))->toBeTrue();
    $page->click('[data-test="confirm-cancel-notice"]');

    expect(browserSoon($page, "document.querySelector('[data-test=\"notice-{$cancelled}\"] [data-test=\"status\"]')?.textContent === 'Cancelled'"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"more-{$cancelled}\"]') === null"))->toBeTrue()
        ->and(DB::table('ops.notices')->where('id', $cancelled)->value('status'))->toBe('CANCELLED');
    $page->assertNoJavaScriptErrors();
});

it('keeps a row reached with Show More in the list when it is cancelled, saying Cancelled', function () {
    $run = writtenBrowserRun();
    $ids = array_map(static fn (int $i): string => writtenBrowserNotice("Deep {$i} {$run}"), range(1, 25));
    $page = writtenBrowser(['ops.notice.manage']);
    $rows = "document.querySelectorAll('[data-test^=\"notice-\"]')";
    // The first written is the last of the newest 25: on the second page.
    $deep = $ids[0];

    $page->navigate('/admin/notices?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "{$rows}.length === 20"))->toBeTrue();
    $page->click('[data-test="more"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"more-{$deep}\"]') !== null"))->toBeTrue();
    $shown = (int) $page->script("{$rows}.length");

    $page->script("(() => { document.querySelector('[data-test=\"more-{$deep}\"]').scrollIntoView({ block: 'center' }); })()");
    expect(browserSoon($page, 'window.scrollY > 0'))->toBeTrue();
    $place = (int) $page->script('Math.round(window.scrollY)');
    $page->click("[data-test=\"more-{$deep}\"]")->click('[data-test="cancel-notice"]');
    expect(browserSoon($page, "document.body.textContent.includes('Cancel this notice?')"))->toBeTrue();
    $page->click('[data-test="confirm-cancel-notice"]');

    expect(browserSoon($page, "document.querySelector('[data-test=\"notice-{$deep}\"] [data-test=\"status\"]')?.textContent === 'Cancelled'"))->toBeTrue(
        'saved as '.DB::table('ops.notices')->where('id', $deep)->value('status').", {$page->script("{$rows}.length")} rows of {$shown}",
    )->and((int) $page->script("{$rows}.length"))->toBe($shown)
        // The place stays: the browser cuts it down while the first page alone is drawn.
        ->and(browserSoon($page, "Math.abs(window.scrollY - {$place}) < 5", 5))->toBeTrue("scrolled to {$page->script('Math.round(window.scrollY)')}, was {$place}");

    // A refusal at this depth keeps its words on the page once the rows are back.
    $other = $ids[1];
    DB::table('ops.notices')->where('id', $other)->update(['status' => 'SENT', 'started_at' => now(), 'finished_at' => now()]);
    $page->script("(() => { document.querySelector('[data-test=\"more-{$other}\"]').scrollIntoView({ block: 'center' }); })()");
    $page->click("[data-test=\"more-{$other}\"]")->click('[data-test="cancel-notice"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-cancel-notice\"]') !== null"))->toBeTrue();
    $page->click('[data-test="confirm-cancel-notice"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"notice-{$other}\"] [data-test=\"status\"]')?.textContent === 'Sent' && {$rows}.length === {$shown}"))->toBeTrue();
    usleep(500_000);
    expect((bool) $page->script("document.querySelector('main').textContent.includes('has started sending')"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('keeps a live segment on Edit, and asks before Send Now', function () {
    $run = writtenBrowserRun();
    $sara = Wx::agreeing("edit{$run}@example.test", 'ae');
    $segment = writtenBrowserSegment("Kept {$run}", [$sara]);
    $notice = writtenBrowserNotice("Kept {$run}", ['segment_id' => $segment]);
    $page = writtenBrowser(['ops.notice.manage']);

    $page->navigate("/admin/notices/{$notice}/edit", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "(document.querySelector('[data-test=\"segment\"]')?.textContent ?? '').includes('Kept {$run}')"))->toBeTrue();

    // Saved on its time: the audience stays.
    $page->type('#title_en', "Kept again {$run}")->click('[data-test="send"]');
    expect(browserSoon($page, "window.location.pathname === '/admin/notices'"))->toBeTrue()
        ->and(DB::table('ops.notices')->where('id', $notice)->value('segment_id'))->toBe($segment);

    // Now asks first, naming the segment.
    $page->navigate("/admin/notices/{$notice}/edit", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"when-none\"]') !== null"))->toBeTrue();
    $page->click('[data-test="when-none"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"send\"]').textContent.includes('Send Now')"))->toBeTrue();
    $page->click('[data-test="send"]');
    expect(browserSoon($page, "document.querySelector('[role=\"dialog\"] h2')?.textContent.replace(/[\u2068\u2069]/g, '') === 'Send to Kept {$run} (1 member)?'"))->toBeTrue()
        ->and(DB::table('ops.notices')->where('id', $notice)->value('status'))->toBe('SCHEDULED');
    $page->click('[data-test="confirm-send"]');
    expect(browserSoon($page, "window.location.pathname === '/admin/notices' && document.body.textContent.includes('Notice on its way.')"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('says so when a notice started meanwhile, on the list and on its form', function () {
    $run = writtenBrowserRun();
    $onList = writtenBrowserNotice("List {$run}");
    $onForm = writtenBrowserNotice("Form {$run}");
    $page = writtenBrowser(['ops.notice.manage']);

    $page->navigate('/admin/notices?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"more-{$onList}\"]') !== null"))->toBeTrue();
    DB::table('ops.notices')->where('id', $onList)->update(['status' => 'SENT', 'started_at' => now(), 'finished_at' => now()]);
    $page->click("[data-test=\"more-{$onList}\"]")->click('[data-test="cancel-notice"]');
    expect(browserSoon($page, "document.body.textContent.includes('Cancel this notice?')"))->toBeTrue();
    $page->click('[data-test="confirm-cancel-notice"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"notice-{$onList}\"] [data-test=\"status\"]')?.textContent === 'Sent' && [...document.querySelectorAll('[data-sonner-toast]')].some((toast) => toast.textContent.includes('has started sending'))"))->toBeTrue();

    $page->navigate("/admin/notices/{$onForm}/edit", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('#title_en')?.value === 'Form {$run}'"))->toBeTrue();
    $page->type('#title_en', "Typed {$run}");
    DB::table('ops.notices')->where('id', $onForm)->update(['status' => 'SENT', 'started_at' => now(), 'finished_at' => now()]);
    $page->click('[data-test="send"]');

    // The form stays, with what was typed, the words and the way back.
    expect(browserSoon($page, "document.body.textContent.includes('has started sending')"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"back-to-list\"]') !== null", 5))->toBeTrue()
        ->and($page->script('location.pathname'))->toBe("/admin/notices/{$onForm}/edit")
        ->and($page->script("document.querySelector('#title_en').value"))->toBe("Typed {$run}");
    $page->assertNoJavaScriptErrors();
});

it('makes a notice whose segment was deleted wait for another choice', function () {
    $run = writtenBrowserRun();
    $notice = writtenBrowserNotice("Gone {$run}", ['segment_id' => strtolower((string) Str::ulid())]);
    $page = writtenBrowser(['ops.notice.manage']);

    $page->navigate("/admin/notices/{$notice}/edit", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"segment-deleted\"]') !== null"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"send\"]').getAttribute('aria-disabled')"))->toBe('true');

    $page->navigate('/admin/notices?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"notice-{$notice}\"] [data-test=\"audience\"]')?.textContent === 'Deleted segment'"))->toBeTrue();
});

it('fits a phone in Arabic with a picker and a row open', function () {
    $run = writtenBrowserRun();
    $sara = Wx::agreeing("phone{$run}@example.test", 'ae');
    writtenBrowserSegment("Phone {$run}", [$sara]);
    $id = strtolower((string) Str::ulid());
    DB::table('ops.staff_messages')->insert([
        'id' => $id, 'store_id' => Fx::storeId('ae'), 'sender_id' => Fx::staff(superAdmin: true), 'audience' => 'STORE', 'title_ar' => 'عنوان', 'title_en' => 'Title', 'body_ar' => 'نص', 'body_en' => 'Body',
        'by_bell' => true, 'by_email' => false, 'status' => 'FAILED', 'created_at' => now(),
    ]);
    $page = writtenBrowser(['ops.message.send_many'], 'ar');
    $page->resize(375, 812);

    $page->navigate('/admin/messages?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"message-{$id}\"]') !== null"))->toBeTrue();
    $page->script("(() => { document.querySelector('[data-test=\"message-{$id}\"] [data-test=\"open\"]').click(); })()");
    expect(browserSoon($page, "document.querySelector('[data-test=\"failed-note\"]') !== null"))->toBeTrue();
    writtenBrowserFits($page);

    $page->navigate('/admin/messages/new?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"who-SEGMENT\"]') !== null"))->toBeTrue();
    $page->click('[data-test="who-SEGMENT"]')->click('[data-test="segment"]');
    expect(browserSoon($page, "document.querySelector('[cmdk-item]') !== null"))->toBeTrue();
    writtenBrowserFits($page);
    $page->assertNoJavaScriptErrors();
});

it('saves a draft With a Campaign on a phone, without asking, and lists it as Draft (ops.md §9.7 #1, O5, O6)', function () {
    $run = writtenBrowserRun();
    // A draft is written as any waiting notice: to a store with someone to send to.
    Wx::agreeing("draft{$run}@example.test", 'ae');
    $page = writtenBrowser(['ops.notice.manage']);
    $page->resize(375, 812);

    $page->navigate('/admin/notices/new?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('#title_ar') !== null && document.querySelector('[data-test=\"when-CAMPAIGN\"]') !== null"))->toBeTrue();
    writtenBrowserBoxes($page, "Draft {$run}");
    $page->click('[data-test="when-CAMPAIGN"]');

    expect(browserSoon($page, "document.querySelector('[data-test=\"when-campaign-helper\"]')?.textContent === 'Sent when a campaign that names it goes live.' && document.querySelector('#when-time') === null"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"send\"]').textContent.includes('Save Draft')"))->toBeTrue();
    writtenBrowserFits($page);

    $page->click('[data-test="send"]');
    expect(browserSoon($page, "window.location.pathname === '/admin/notices' && document.body.textContent.includes('Draft saved.')"))->toBeTrue();

    $id = (string) DB::table('ops.notices')->where('title_en', "Draft {$run}")->value('id');
    expect(DB::table('ops.notices')->where('id', $id)->value('status'))->toBe('DRAFT')
        ->and(DB::table('ops.notices')->where('id', $id)->value('send_at'))->toBeNull()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"notice-{$id}\"] [data-test=\"status\"]')?.textContent === 'Draft' && document.querySelector('[data-test=\"notice-{$id}\"] [data-test=\"with-campaign\"]') !== null"))->toBeTrue();
    writtenBrowserFits($page);

    // Edit opens on With a Campaign, and keeps it a draft.
    $page->navigate("/admin/notices/{$id}/edit", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"when-CAMPAIGN\"]')?.getAttribute('data-state') === 'checked'"))->toBeTrue();
    $page->type('#title_en', "Draft again {$run}")->click('[data-test="send"]');
    expect(browserSoon($page, "window.location.pathname === '/admin/notices'"))->toBeTrue()
        ->and(DB::table('ops.notices')->where('id', $id)->value('status'))->toBe('DRAFT');
    $page->assertNoJavaScriptErrors();
});

it('draws all four pages right to left in Arabic, in Latin digits, and fits a phone', function () {
    $run = writtenBrowserRun();
    $notice = writtenBrowserNotice("Arabic {$run}");
    $page = writtenBrowser(['ops.notice.manage', 'ops.message.send_one', 'ops.message.send_many'], 'ar');
    $page->resize(375, 812);

    foreach (['/admin/messages?store=ae', '/admin/messages/new?store=ae', '/admin/notices?store=ae', '/admin/notices/new?store=ae', "/admin/notices/{$notice}/edit"] as $address) {
        $page->navigate($address, BROWSER_SLOW_PAGE_LOAD);
        expect(browserSoon($page, "document.documentElement.dir === 'rtl' && document.querySelector('main') !== null"))->toBeTrue($address)
            ->and((bool) $page->script('/[٠-٩]/.test(document.body.textContent)'))->toBeFalse($address);
        writtenBrowserFits($page);
    }

    $page->assertNoJavaScriptErrors();
});
