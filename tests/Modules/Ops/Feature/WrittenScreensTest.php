<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\CapturedEmails;
use Tests\Modules\Ops\Support\WrittenFixtures as Wx;

use function Pest\Laravel\seed;

/*
| O3-O6, Customer Messages and Offer Notices as pages (ops.md §4.15): a plain visit gets the page and JSON stays for
| whoever asks; a form the page sends goes back with its words, each refusal on its box. WrittenRoutesTest and
| ScreensReadsTest keep the JSON answers.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    CapturedEmails::install();
});

function writtenScreenStaff(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.7.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/** @return array<string, string> */
function writtenScreenInertia(string $from): array
{
    return ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_REFERER' => url($from)];
}

/**
 * The words a form went back with.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, list<string>>
 */
function writtenScreenErrors(TestResponse $response): array
{
    $errors = AdminBrowser::flashed($response, 'errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->getBag('default')->toArray();
    }

    return is_array($errors) ? ($errors['default']['messages'] ?? []) : [];
}

/**
 * @param  array<string, string>  $extra
 * @return array<string, string>
 */
function writtenScreenText(array $extra = []): array
{
    return ['store' => 'sa', 'title_ar' => 'ع', 'title_en' => 'Hello', 'body_ar' => 'ن', 'body_en' => 'Text', 'by_bell' => '1', ...$extra];
}

function writtenScreenNotice(string $writer, string $status = 'SCHEDULED'): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('ops.notices')->insert([
        'id' => $id, 'store_id' => Fx::storeId('sa'), 'title_ar' => 'ع', 'title_en' => 'T', 'body_ar' => 'ن', 'body_en' => 'B', 'by_bell' => true, 'by_email' => false,
        'status' => $status, 'send_at' => now()->addDay(), 'created_by' => $writer, 'created_at' => now(), 'updated_at' => now(),
        'started_at' => $status === 'SENT' ? now() : null, 'finished_at' => $status === 'SENT' ? now() : null,
    ]);

    return $id;
}

function writtenScreenLater(): string
{
    return CarbonImmutable::now('Asia/Riyadh')->addDay()->setTime(10, 0)->format('Y-m-d H:i');
}

it('gives a plain visit the messages page, and JSON to whoever asks', function () {
    $browser = writtenScreenStaff(Fx::staffWith(['ops.message.send_many'], ['sa', 'ae'], RoleLevel::Admin));

    $browser->get('/admin/messages?store=ae')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Ops/Admin/Messages/Index')
        ->where('storeCode', 'ae')
        ->has('stores', 2)
        ->has('messages', 0)
        ->where('next', null)
        ->where('translations', fn (Collection $words): bool => $words->has('ops::admin_written.messages.title') && $words->has('ops::errors.fields.title_ar')));

    expect(array_keys($browser->get('/admin/messages?store=ae', ['HTTP_ACCEPT' => 'application/json'])->assertOk()->json()))->toBe(['messages', 'next']);
});

it('gives a plain visit the notices page with the store\'s zone, and JSON to whoever asks', function () {
    $browser = writtenScreenStaff(Fx::staffWith(['ops.notice.manage'], ['sa'], RoleLevel::Admin));

    $browser->get('/admin/notices')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Ops/Admin/Notices/Index')
        ->where('storeCode', 'sa')
        ->where('timezone', 'Asia/Riyadh')
        ->has('stores', 1)
        ->has('notices', 0));

    expect(array_keys($browser->get('/admin/notices', ['HTTP_ACCEPT' => 'application/json'])->assertOk()->json()))->toBe(['notices', 'next']);
});

it('offers New Message only what the person may send, store by store', function () {
    $browser = writtenScreenStaff(Fx::staffWith(['ops.message.send_one'], ['sa'], RoleLevel::Admin));

    $browser->get('/admin/messages/new')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Ops/Admin/Messages/New')
        ->where('storeCode', 'sa')
        ->has('toOne', 1)
        ->has('toMany', 0));

    writtenScreenStaff(Fx::staffWith(['ops.report.view'], ['sa']))->get('/admin/messages/new')->assertForbidden();
    writtenScreenStaff(Fx::staffWith(['ops.report.view'], ['sa']))->get('/admin/notices/new')->assertForbidden();
});

it('opens Edit on the notice with its store only, and sends one that started back to the list', function () {
    $staff = Fx::staffWith(['ops.notice.manage'], ['sa', 'ae'], RoleLevel::Admin);
    $browser = writtenScreenStaff($staff);
    $waiting = writtenScreenNotice($staff);
    $sent = writtenScreenNotice($staff, 'SENT');

    $browser->get("/admin/notices/{$waiting}/edit")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Ops/Admin/Notices/Form')
        ->where('notice.id', $waiting)
        ->where('storeCode', 'sa')
        ->has('stores', 1)
        ->where('stores.0.timezone', 'Asia/Riyadh'));

    $browser->get('/admin/notices/new?store=ae')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('notice', null)
        ->where('storeCode', 'ae')
        ->has('stores', 2));

    $answer = $browser->get("/admin/notices/{$sent}/edit")->assertRedirect('/admin/notices?store=sa');
    expect(writtenScreenErrors($answer)['form'][0])->toBe(__('ops::errors.notice_already_started.detail'));
});

it('sends a message from the page and goes to the list with its toast', function () {
    $omar = Fx::customer('omar@example.test');
    $browser = writtenScreenStaff(Fx::staffWith(['ops.message.send_one', 'access.customer.view'], ['sa'], RoleLevel::Admin));

    $answer = $browser->post('/admin/messages', writtenScreenText(['audience' => 'ONE', 'customer' => $omar]), writtenScreenInertia('/admin/messages/new'))
        ->assertRedirect('/admin/messages?store=sa');

    expect(AdminBrowser::flashed($answer, 'status'))->toBe(__('ops::admin_written.messages.toast'))
        ->and(DB::table('ops.staff_messages')->where('customer_id', $omar)->count())->toBe(1);
});

it('puts a message\'s refusal on its box, in words', function () {
    $omar = Fx::customer('omar@example.test');
    $browser = writtenScreenStaff(Fx::staffWith(['ops.message.send_one', 'ops.message.send_many', 'access.customer.view'], ['sa'], RoleLevel::Admin));
    $from = writtenScreenInertia('/admin/messages/new');

    $title = writtenScreenErrors($browser->post('/admin/messages', writtenScreenText(['audience' => 'ONE', 'customer' => $omar, 'title_en' => '']), $from)->assertRedirect('/admin/messages/new'));
    expect(array_keys($title))->toBe(['title_en'])
        ->and($title['title_en'][0])->toContain(__('ops::errors.fields.title_en'));

    expect(writtenScreenErrors($browser->post('/admin/messages', writtenScreenText(['audience' => 'ONE', 'customer' => $omar, 'by_bell' => '']), $from)))->toHaveKey('channels')
        ->and(writtenScreenErrors($browser->post('/admin/messages', writtenScreenText(['audience' => 'ONE', 'customer' => strtolower((string) Str::ulid())]), $from)))->toHaveKey('customer')
        ->and(writtenScreenErrors($browser->post('/admin/messages', writtenScreenText(['audience' => 'SEGMENT']), $from)))->toHaveKey('audience');
});

it('says a segment gone in the form\'s error, marked so the page empties its picker', function () {
    $browser = writtenScreenStaff(Fx::staffWith(['ops.message.send_many'], ['sa'], RoleLevel::Admin));

    $errors = writtenScreenErrors($browser->post('/admin/messages', writtenScreenText(['audience' => 'SEGMENT', 'segment' => strtolower((string) Str::ulid())]), writtenScreenInertia('/admin/messages/new')));

    expect($errors)->toHaveKeys(['form', 'segment_gone'])
        ->and($errors['form'][0])->toBe(__('promotions::errors.segment_not_found.detail'));
});

it('refuses a message on the page to someone who may not send it, in words', function () {
    $browser = writtenScreenStaff(Fx::staffWith(['ops.message.send_one'], ['sa'], RoleLevel::Admin));

    expect(writtenScreenErrors($browser->post('/admin/messages', writtenScreenText(['audience' => 'STORE']), writtenScreenInertia('/admin/messages/new'))))->toHaveKey('form');
});

it('schedules and sends a notice from the page, the toast saying which', function () {
    Wx::agreeing('sara@example.test');
    $browser = writtenScreenStaff(Fx::staffWith(['ops.notice.manage'], ['sa'], RoleLevel::Admin));
    $from = writtenScreenInertia('/admin/notices/new');
    $at = writtenScreenLater();

    $scheduled = $browser->post('/admin/notices', writtenScreenText(['send_at' => $at, 'segment' => '', 'link' => 'categories/chairs']), $from)->assertRedirect('/admin/notices?store=sa');
    expect(AdminBrowser::flashed($scheduled, 'status'))->toBe(__('ops::admin_written.notices.toast_scheduled', ['at' => $at]))
        ->and(DB::table('ops.notices')->where('status', 'SCHEDULED')->value('link'))->toBe('categories/chairs');

    $now = $browser->post('/admin/notices', writtenScreenText(['send_at' => '', 'segment' => '']), $from)->assertRedirect('/admin/notices?store=sa');
    expect(AdminBrowser::flashed($now, 'status'))->toBe(__('ops::admin_written.notices.toast_now'));
});

it('puts a notice\'s refusal on its box, and a start meanwhile in the form with its mark', function () {
    Wx::agreeing('sara@example.test');
    $staff = Fx::staffWith(['ops.notice.manage'], ['sa'], RoleLevel::Admin);
    $browser = writtenScreenStaff($staff);
    $from = writtenScreenInertia('/admin/notices/new');
    $at = writtenScreenLater();

    expect(writtenScreenErrors($browser->post('/admin/notices', writtenScreenText(['send_at' => '2020-01-01 10:00']), $from)))->toHaveKey('send_at')
        ->and(writtenScreenErrors($browser->post('/admin/notices', writtenScreenText(['send_at' => $at, 'link' => 'a:b']), $from)))->toHaveKey('link');

    $sent = writtenScreenNotice($staff, 'SENT');

    $edit = writtenScreenErrors($browser->post("/admin/notices/{$sent}", writtenScreenText(['send_at' => $at, 'segment' => '']), writtenScreenInertia("/admin/notices/{$sent}/edit"))->assertRedirect("/admin/notices/{$sent}/edit"));
    expect($edit)->toHaveKeys(['form', 'started'])
        ->and($edit['form'][0])->toBe(__('ops::errors.notice_already_started.detail'));

    // The form stays for the answer that goes back to it; a later visit goes to the list.
    $browser->get("/admin/notices/{$sent}/edit")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Ops/Admin/Notices/Form')->where('errors.started', '1'));
    $browser->get("/admin/notices/{$sent}/edit")->assertRedirect('/admin/notices?store=sa');
});

it('cancels a notice from the list, and says so when it started meanwhile', function () {
    $staff = Fx::staffWith(['ops.notice.manage'], ['sa'], RoleLevel::Admin);
    $browser = writtenScreenStaff($staff);
    $waiting = writtenScreenNotice($staff);
    $from = writtenScreenInertia('/admin/notices');

    $answer = $browser->post("/admin/notices/{$waiting}/cancel", [], $from)->assertRedirect('/admin/notices');
    expect(AdminBrowser::flashed($answer, 'status'))->toBe(__('ops::admin_written.notices.cancel.toast'))
        ->and(DB::table('ops.notices')->where('id', $waiting)->value('status'))->toBe('CANCELLED');

    expect(writtenScreenErrors($browser->post("/admin/notices/{$waiting}/cancel", [], $from)->assertRedirect('/admin/notices'))['form'][0])->toBe(__('ops::errors.notice_already_started.detail'));
});

it('keeps JSON for a change no page sent', function () {
    Wx::agreeing('sara@example.test');
    $staff = Fx::staffWith(['ops.notice.manage'], ['sa'], RoleLevel::Admin);
    $browser = writtenScreenStaff($staff);
    $at = writtenScreenLater();

    $browser->post('/admin/notices', writtenScreenText(['send_at' => $at]))->assertCreated()->assertJsonPath('sendAtLocal', $at);
    $browser->post('/admin/notices', writtenScreenText(['send_at' => 'nope']), ['HTTP_ACCEPT' => 'application/json'])->assertUnprocessable()->assertJsonPath('type', 'ops.invalid_ops_input');
});

it('offers the two menu entries to whoever may use them', function () {
    $menu = static fn (string $staffId): array => array_column(array_merge(...array_column(writtenScreenStaff($staffId)->get('/admin')->viewData('page')['props']['menu'], 'entries')), 'key');

    $ours = static fn (string $staffId): array => array_values(array_intersect($menu($staffId), ['notices', 'messages']));

    expect($ours(Fx::staffWith(['ops.message.send_one'], ['sa'], RoleLevel::Admin)))->toBe(['messages'])
        ->and($ours(Fx::staffWith(['ops.message.send_many'], ['sa'], RoleLevel::Admin)))->toBe(['messages'])
        ->and($ours(Fx::staffWith(['ops.notice.manage'], ['sa'], RoleLevel::Admin)))->toBe(['notices']);
});
