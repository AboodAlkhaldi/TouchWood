<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Ops\Application\Command\CancelNotice\CancelNotice;
use Modules\Ops\Application\Command\CancelNotice\CancelNoticeHandler;
use Modules\Ops\Application\Command\ChangeNotice\ChangeNotice;
use Modules\Ops\Application\Command\ChangeNotice\ChangeNoticeHandler;
use Modules\Ops\Application\Command\CreateNotice\CreateNotice;
use Modules\Ops\Application\Command\CreateNotice\CreateNoticeHandler;
use Modules\Ops\Application\Delivery\Broadcast;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Application\Query\ListNotices\ListNotices;
use Modules\Ops\Application\Query\ListNotices\ListNoticesHandler;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Exception\NoticeAlreadyStarted;
use Modules\Ops\Domain\Model\BroadcastStatus;
use Modules\Ops\Domain\Repository\Notices;
use Modules\Ops\Infrastructure\Queue\SendBroadcastEmailJob;
use Modules\Ops\Infrastructure\Queue\StartDueNoticesJob;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Ops\Support\CapturedEmails;
use Tests\Modules\Ops\Support\WrittenFixtures as Wx;

use function Pest\Laravel\seed;

/*
| Offer notices (ops.md §1.7, §1.3, §7): to every customer of the store, by the bell to all and by
| email only to those who agreed (verified, active, no deletion pending); now or scheduled in the
| store's time zone; changed or cancelled until it starts; once per recipient; a failure counted.
| The queue is `sync` in tests: a notice sent now has gone by the time the command returns.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    CapturedEmails::install();
});

function noticesCreate(string $storeCode = 'sa', ?string $sendAt = null, bool $bell = true, bool $email = true, ?string $link = 'categories/chairs'): string
{
    return app(CreateNoticeHandler::class)->handle(new CreateNotice(
        Fx::storeId($storeCode),
        'عروض رمضان بدأت',
        'Ramadan offers have started',
        'خصم على كل الكراسي.',
        'A discount on every chair.',
        $link,
        $bell,
        $email,
        $sendAt,
    ));
}

/** @return object{status: string, bell_count: int, email_count: int, failed_count: int} */
function noticesRow(string $id): object
{
    /** @var object{status: string, bell_count: int, email_count: int, failed_count: int} */
    return DB::table('ops.notices')->where('id', $id)->first();
}

it('sends now: a bell entry to every customer of the store, an email only to those who agreed', function () {
    $agreeing = Wx::agreeing('sara@example.test', 'sa', 'ar');
    $silent = Fx::customer('omar@example.test');
    $company = Wx::agreeing('firm@example.test', 'sa', 'en', 'company');
    $blocked = Wx::agreeing('blocked@example.test');
    Wx::set($blocked, ['status' => 'BLOCKED']);
    $leaving = Wx::agreeing('leaving@example.test');
    Wx::set($leaving, ['deletion_scheduled_for' => CarbonImmutable::now()->addDays(14)->toIso8601String()]);
    $unverified = Wx::agreeing('unverified@example.test');
    Wx::set($unverified, ['email_verified_at' => null]);
    $elsewhere = Wx::agreeing('ae@example.test', 'ae');
    Fx::actAsAdmin(['sa'], ['ops.notice.manage']);

    $id = noticesCreate();

    $bells = DB::table('ops.inbox_entries')->where('kind', 'offer_notice');
    $recipients = [$agreeing, $silent, $company, $blocked, $leaving, $unverified];
    sort($recipients);
    expect((clone $bells)->orderBy('customer_id')->pluck('customer_id')->all())->toBe($recipients)
        ->and((clone $bells)->where('customer_id', $agreeing)->value('link'))->toBe('/sa/ar/categories/chairs')
        ->and((clone $bells)->where('customer_id', $silent)->value('link'))->toBe('/sa/en/categories/chairs')
        ->and((clone $bells)->where('customer_id', $agreeing)->value('title_en'))->toBe('Ramadan offers have started')
        ->and(CapturedEmails::installed()->to())->toEqualCanonicalizing(['sara@example.test', 'firm@example.test'])
        ->and(noticesRow($id))->toMatchArray(['status' => 'SENT', 'bell_count' => 6, 'email_count' => 2, 'failed_count' => 0])
        ->and(DB::table('ops.sent_messages')->where('kind', 'offer_notice')->where('status', 'SENT')->count())->toBe(2)
        ->and(DB::table('ops.inbox_entries')->where('customer_id', $elsewhere)->count())->toBe(0);

    // In the customer's own language, from the store, with the page and the unsubscribe link.
    $arabic = CapturedEmails::installed()->for('sara@example.test');
    expect($arabic->subject)->toBe('عروض رمضان بدأت')
        ->and($arabic->text)->toContain('مرحبًا Sara،')
        ->and($arabic->text)->toContain('خصم على كل الكراسي.')
        ->and($arabic->text)->toContain(rtrim((string) config('app.url'), '/').'/sa/ar/categories/chairs')
        ->and($arabic->text)->toContain('/sa/ar/unsubscribe/'.$agreeing.'?signature=')
        ->and($arabic->html)->toContain('dir="rtl"');
});

it('sends by the bell only, or by email only, as ticked', function () {
    $sara = Wx::agreeing('sara@example.test');
    Fx::actAsAdmin(['sa'], ['ops.notice.manage']);

    noticesCreate(email: false);
    expect(CapturedEmails::installed()->sent)->toBe([])
        ->and(DB::table('ops.inbox_entries')->where('customer_id', $sara)->count())->toBe(1);

    noticesCreate(bell: false, link: null);
    expect(CapturedEmails::installed()->to())->toBe(['sara@example.test'])
        ->and(DB::table('ops.inbox_entries')->where('customer_id', $sara)->count())->toBe(1)
        ->and(CapturedEmails::installed()->sent[0]->text)->not->toContain('/categories/');
});

it('refuses a notice with no channel, a time already past, a link that leaves the shop, or a store with no customers', function () {
    Fx::customer();
    Fx::actAsAdmin(['sa', 'ae'], ['ops.notice.manage']);

    expect(fn () => noticesCreate(bell: false, email: false))->toThrow(InvalidOpsInput::class, 'Invalid channels')
        ->and(fn () => noticesCreate(sendAt: CarbonImmutable::now('Asia/Riyadh')->subMinute()->format('Y-m-d H:i')))->toThrow(InvalidOpsInput::class, 'Invalid send_at')
        ->and(fn () => noticesCreate(sendAt: '2026-02-30 10:00'))->toThrow(InvalidOpsInput::class, 'Invalid send_at')
        ->and(fn () => noticesCreate(link: 'https://elsewhere.test'))->toThrow(InvalidOpsInput::class, 'Invalid link')
        ->and(fn () => noticesCreate(link: 'categories//chairs'))->toThrow(InvalidOpsInput::class, 'Invalid link')
        ->and(fn () => noticesCreate(link: 'a'.chr(92).'b'))->toThrow(InvalidOpsInput::class, 'Invalid link')
        ->and(fn () => noticesCreate('ae'))->toThrow(InvalidOpsInput::class, 'Invalid audience')
        ->and(DB::table('ops.notices')->count())->toBe(0);
});

it('is ops.notice.manage\'s, in the notice\'s store', function () {
    Fx::customer();
    Fx::customer('ae@example.test', 'ae');
    Fx::actAsStaff(Fx::staffWith(['ops.message.send_one'], ['sa']));
    expect(fn () => noticesCreate())->toThrow(Unauthorized::class);

    Fx::actAsStaff(Fx::staffWith(['ops.notice.manage'], ['ae']));
    expect(fn () => noticesCreate())->toThrow(Unauthorized::class)
        ->and(noticesCreate('ae'))->toBeString();
});

it('waits for its time in the store\'s time zone, can be changed or cancelled until then, and starts within the minute', function () {
    Wx::agreeing('sara@example.test');
    Fx::actAsAdmin(['sa'], ['ops.notice.manage']);
    $tomorrow = CarbonImmutable::now('Asia/Riyadh')->addDay()->setTime(9, 30);

    $id = noticesCreate(sendAt: $tomorrow->format('Y-m-d\TH:i'));
    expect(noticesRow($id)->status)->toBe('SCHEDULED')
        ->and(CarbonImmutable::parse((string) DB::table('ops.notices')->where('id', $id)->value('send_at'))->equalTo($tomorrow))->toBeTrue()
        ->and(DB::table('ops.inbox_entries')->count())->toBe(0);

    app(ChangeNoticeHandler::class)->handle(new ChangeNotice($id, 'عرض', 'Offer', 'نص', 'Text', null, true, false, $tomorrow->addHour()->format('Y-m-d H:i')));
    $page = app(ListNoticesHandler::class)->handle(new ListNotices(Fx::storeId('sa')));
    expect($page->notices[0]->text->titleEn)->toBe('Offer')
        ->and($page->notices[0]->byEmail)->toBeFalse()
        ->and($page->notices[0]->status)->toBe(BroadcastStatus::Scheduled);

    // Not yet: the minute's job leaves it waiting.
    (new StartDueNoticesJob)->handle(app('db.connection'), app(Notices::class), app(Dispatcher::class));
    expect(noticesRow($id)->status)->toBe('SCHEDULED');

    CarbonImmutable::setTestNow($tomorrow->addHour()->addMinute());
    (new StartDueNoticesJob)->handle(app('db.connection'), app(Notices::class), app(Dispatcher::class));
    CarbonImmutable::setTestNow();

    expect(noticesRow($id))->toMatchArray(['status' => 'SENT', 'bell_count' => 1, 'email_count' => 0])
        ->and(fn () => app(CancelNoticeHandler::class)->handle(new CancelNotice($id)))->toThrow(NoticeAlreadyStarted::class)
        ->and(fn () => app(ChangeNoticeHandler::class)->handle(new ChangeNotice($id, 'a', 'b', 'c', 'd', null, true, true, null)))->toThrow(NoticeAlreadyStarted::class);
});

it('cancels a waiting notice for good: it never sends', function () {
    Wx::agreeing('sara@example.test');
    Fx::actAsAdmin(['sa'], ['ops.notice.manage']);
    $id = noticesCreate(sendAt: CarbonImmutable::now('Asia/Riyadh')->addHour()->format('Y-m-d H:i'));

    app(CancelNoticeHandler::class)->handle(new CancelNotice($id));
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(2));
    (new StartDueNoticesJob)->handle(app('db.connection'), app(Notices::class), app(Dispatcher::class));
    CarbonImmutable::setTestNow();

    expect(noticesRow($id)->status)->toBe('CANCELLED')
        ->and(DB::table('ops.inbox_entries')->count())->toBe(0)
        ->and(CapturedEmails::installed()->sent)->toBe([])
        ->and(fn () => app(CancelNoticeHandler::class)->handle(new CancelNotice($id)))->toThrow(NoticeAlreadyStarted::class);
});

it('sends one email once by its delivery id, and counts a failure the queue gave up on', function () {
    $sara = Wx::agreeing('sara@example.test');
    Fx::actAsAdmin(['sa'], ['ops.notice.manage']);
    $id = noticesCreate(email: false);
    $delivery = (string) Str::uuid();

    $job = new SendBroadcastEmailJob(Broadcast::Notice, $id, $sara, $delivery);
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);
    expect(CapturedEmails::installed()->to())->toBe(['sara@example.test'])
        ->and(noticesRow($id)->email_count)->toBe(1);

    CapturedEmails::installed()->fails = true;
    $again = new SendBroadcastEmailJob(Broadcast::Notice, $id, $sara, (string) Str::uuid());
    expect(fn () => app()->call([$again, 'handle']))->toThrow(ProviderFailed::class);
    $again->failed();

    expect(noticesRow($id)->failed_count)->toBe(1)
        ->and(DB::table('ops.sent_messages')->where('status', 'FAILED')->where('customer_id', $sara)->count())->toBe(1);
});

it('checks the customer again as the email leaves: consent withdrawn meanwhile, no email', function () {
    $sara = Wx::agreeing('sara@example.test');
    Fx::actAsAdmin(['sa'], ['ops.notice.manage']);
    $id = noticesCreate(email: false);
    Wx::set($sara, ['marketing_consent' => false]);

    app()->call([new SendBroadcastEmailJob(Broadcast::Notice, $id, $sara, (string) Str::uuid()), 'handle']);

    expect(CapturedEmails::installed()->sent)->toBe([]);
});
