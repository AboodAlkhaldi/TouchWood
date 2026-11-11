<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\QueryException;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Enums\StaffNotificationTopic;
use Modules\Content\Public\Contracts\CampaignNotices;
use Modules\Content\Public\Contracts\ContentApi;
use Modules\Content\Public\Dto\CampaignMessageDto;
use Modules\Content\Public\Dto\NoticeChoiceDto;
use Modules\Content\Public\Events\CampaignExpiring;
use Modules\Content\Public\Events\CampaignWentLive;
use Modules\Ops\Application\Command\CancelNotice\CancelNotice;
use Modules\Ops\Application\Command\CancelNotice\CancelNoticeHandler;
use Modules\Ops\Application\Command\CreateNotice\CreateNotice;
use Modules\Ops\Application\Command\CreateNotice\CreateNoticeHandler;
use Modules\Ops\Infrastructure\Listener\AlertStaff;
use Modules\Ops\Infrastructure\Listener\StartCampaignNotice;
use Modules\Ops\Infrastructure\Queue\StartDueNoticesJob;
use Modules\Platform\Public\Dto\TranslatedTextDto;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Outbox\Outbox;
use Shared\Infrastructure\Outbox\OutboxRelay;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\CapturedEmails;
use Tests\Modules\Ops\Support\WrittenFixtures as Wx;
use Tests\TestCase;

use function Pest\Laravel\seed;

/*
| Ops' side of the campaign links (ops.md §1.6 S8, §1.7): Content's two events, fired here directly and
| through the outbox because nothing else fires them until Content is built. S8 alerts the store's staff by
| the campaign's name, read through a stand-in for ContentApi; the going-live notice starts a waiting notice
| once, and nothing else.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    RecordingSecurityMessages::install();
    CapturedEmails::install();
});

/** ContentApi's read answering this campaign, or these answers one after the other. */
function campaignEventsContent(?CampaignMessageDto ...$answers): void
{
    $content = Mockery::mock(ContentApi::class);
    $content->allows('campaignForMessage')->andReturn(...($answers === [] ? [null] : $answers));
    app()->instance(ContentApi::class, $content);
}

function campaignEventsCampaign(string $storeCode = 'sa'): CampaignMessageDto
{
    return new CampaignMessageDto(strtolower((string) Str::ulid()), Fx::storeId($storeCode), new TranslatedTextDto('عروض رمضان', 'Ramadan Offers'));
}

/** Ends at 22:00 UTC on 30 November: 1 December in Saudi Arabia, the store's own day. */
function campaignEventsExpiring(string $storeCode = 'sa'): CampaignExpiring
{
    return new CampaignExpiring((string) Str::uuid(), strtolower((string) Str::ulid()), Fx::storeId($storeCode), CarbonImmutable::parse('2026-11-30T22:00:00+00:00'), CarbonImmutable::now());
}

function campaignEventsLive(?string $noticeId, string $storeCode = 'sa'): CampaignWentLive
{
    return new CampaignWentLive((string) Str::uuid(), strtolower((string) Str::ulid()), Fx::storeId($storeCode), $noticeId, CarbonImmutable::now());
}

function campaignEventsSwitch(string $staffId, bool $panel, bool $email): void
{
    DB::table('access.staff_notification_preferences')->insert(['staff_user_id' => $staffId, 'topic' => StaffNotificationTopic::CampaignExpiry->value, 'panel' => $panel, 'email' => $email]);
    cache()->flush();
}

function campaignEventsBellOf(string $staffId): int
{
    return DB::table('ops.inbox_entries')->where('staff_id', $staffId)->where('kind', 'campaign_expiring')->count();
}

function campaignEventsEmailOf(string $staffId): string
{
    return (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');
}

/** A notice of the store waiting for tomorrow, written by an admin of that store and of the other. */
function campaignEventsNotice(string $storeCode = 'sa'): string
{
    Fx::actAsAdmin(['sa', 'ae'], ['ops.notice.manage']);

    return app(CreateNoticeHandler::class)->handle(new CreateNotice(
        Fx::storeId($storeCode),
        'عروض رمضان بدأت',
        'Ramadan offers have started',
        'خصم على كل الكراسي.',
        'A discount on every chair.',
        null,
        true,
        true,
        CarbonImmutable::now('Asia/Riyadh')->addDay()->format('Y-m-d H:i'),
    ));
}

/** A draft notice of the store, with no time, for a campaign to start (§9.7 #1). */
function campaignEventsDraft(string $storeCode = 'sa'): string
{
    Fx::actAsAdmin(['sa', 'ae'], ['ops.notice.manage']);

    return app(CreateNoticeHandler::class)->handle(new CreateNotice(
        Fx::storeId($storeCode),
        'عروض رمضان بدأت',
        'Ramadan offers have started',
        'خصم على كل الكراسي.',
        'A discount on every chair.',
        null,
        true,
        true,
        // A time typed beside the draft is not read.
        CarbonImmutable::now('Asia/Riyadh')->addDay()->format('Y-m-d H:i'),
        draft: true,
    ));
}

/** @return object{status: string, bell_count: int, email_count: int, send_at: string, started_at: ?string} */
function campaignEventsRow(string $id): object
{
    /** @var object{status: string, bell_count: int, email_count: int, send_at: string, started_at: ?string} */
    return DB::table('ops.notices')->where('id', $id)->first();
}

function campaignEventsNoticeBells(): int
{
    return DB::table('ops.inbox_entries')->where('kind', 'offer_notice')->count();
}

describe('S8, a campaign about to end (§1.6)', function () {
    it('alerts the holders of content.campaign.manage in the store, once per event, with the run\'s page (§9.7 #2, #3)', function () {
        $campaign = campaignEventsCampaign();
        campaignEventsContent($campaign);
        $super = Fx::staff(superAdmin: true);
        $holder = Fx::staffWith(['content.campaign.manage'], ['sa']);
        $elsewhere = Fx::staffWith(['content.campaign.manage'], ['eg']);
        $sendsToMany = Fx::staffWith(['ops.message.send_many'], ['sa'], RoleLevel::Admin);
        $staff = Fx::staffWith(['ops.notice.manage'], ['sa']);
        $event = campaignEventsExpiring();

        event($event);
        event($event);

        $entry = DB::table('ops.inbox_entries')->where('staff_id', $holder)->first();

        expect(campaignEventsBellOf($super))->toBe(1)
            ->and(campaignEventsBellOf($holder))->toBe(1)
            ->and(campaignEventsBellOf($elsewhere))->toBe(0)
            ->and(campaignEventsBellOf($sendsToMany))->toBe(0)
            ->and(campaignEventsBellOf($staff))->toBe(0)
            ->and($entry?->link)->toBe('/admin/content/campaigns/runs/'.$campaign->campaignId)
            ->and($entry?->store_id)->toBe(Fx::storeId('sa'));
    });

    it('writes the bell in both languages, by the campaign\'s name and the store\'s own day, in Latin digits', function () {
        campaignEventsContent(campaignEventsCampaign());
        $super = Fx::staff(superAdmin: true);

        event(campaignEventsExpiring());

        $entry = DB::table('ops.inbox_entries')->where('staff_id', $super)->first();

        expect($entry?->title_en)->toBe('Campaign ending soon')
            ->and($entry?->line_en)->toBe('The Ramadan Offers campaign in Saudi Arabia ends on 2026-12-01.')
            ->and($entry?->title_ar)->toBe('حملة تنتهي قريبًا')
            ->and($entry?->line_ar)->toContain('تنتهي حملة عروض رمضان في ')
            ->and($entry?->line_ar)->toEndWith(' في 2026-12-01.');
    });

    it('goes by each person\'s switches for the topic, the email in their language', function (string $locale, string $subject) {
        campaignEventsContent(campaignEventsCampaign());
        $bellOnly = Fx::staffWith(['content.campaign.manage'], ['sa']);
        $emailOnly = Fx::staffWith(['content.campaign.manage'], ['sa']);
        DB::table('access.staff_users')->where('id', $emailOnly)->update(['locale' => $locale]);
        campaignEventsSwitch($emailOnly, panel: false, email: true);

        event(campaignEventsExpiring());

        $email = CapturedEmails::installed()->last();

        expect(campaignEventsBellOf($bellOnly))->toBe(1)
            ->and(campaignEventsBellOf($emailOnly))->toBe(0)
            ->and(CapturedEmails::installed()->to())->toBe([campaignEventsEmailOf($emailOnly)])
            ->and($email->subject)->toContain($subject)
            ->and($email->text)->toContain($subject)
            ->and($email->text)->toContain('2026-12-01');
    })->with([
        'Arabic' => ['ar', 'تنتهي حملة عروض رمضان في'],
        'English' => ['en', 'The Ramadan Offers campaign in Saudi Arabia ends on 2026-12-01'],
    ]);

    it('sends nothing for a run Content does not know: the binding answers no campaign', function () {
        expect(app(ContentApi::class)->campaignForMessage((string) Str::ulid()))->toBeNull();

        $super = Fx::staff(superAdmin: true);
        campaignEventsSwitch($super, panel: true, email: true);

        event(campaignEventsExpiring());

        expect(campaignEventsBellOf($super))->toBe(0)
            ->and(CapturedEmails::installed()->sent)->toBe([])
            ->and(DB::table('ops.sent_messages')->count())->toBe(0);
    });

    it('sends nothing when the read names another store, logged by its ids alone', function () {
        $log = Log::spy();
        campaignEventsContent(campaignEventsCampaign('eg'));
        $super = Fx::staff(superAdmin: true);
        campaignEventsSwitch($super, panel: true, email: true);

        event(campaignEventsExpiring());

        expect(campaignEventsBellOf($super))->toBe(0)
            ->and(CapturedEmails::installed()->sent)->toBe([]);
        $log->shouldHaveReceived('warning')->withArgs(static fn (string $message, array $context): bool => str_contains($message, 'another store') && ($context['kind'] ?? null) === 'campaign_expiring' && ! str_contains((string) json_encode($context), 'Ramadan'));
    });

    it('removes the email\'s row when the campaign is gone by the time it leaves', function () {
        $campaign = campaignEventsCampaign();
        campaignEventsContent($campaign, $campaign, null);
        $super = Fx::staff(superAdmin: true);
        campaignEventsSwitch($super, panel: true, email: true);

        event(campaignEventsExpiring());

        expect(campaignEventsBellOf($super))->toBe(1)
            ->and(CapturedEmails::installed()->sent)->toBe([])
            ->and(DB::table('ops.sent_messages')->count())->toBe(0);
    });
});

describe('the notice a campaign carries (§1.7)', function () {
    it('starts a waiting notice by the same sending path: a bell to every customer, an email to those who agreed', function () {
        $sara = Wx::agreeing('sara@example.test');
        $omar = Fx::customer('omar@example.test');
        $elsewhere = Wx::agreeing('ae@example.test', 'ae');
        $id = campaignEventsNotice();
        $waitedFor = campaignEventsRow($id)->send_at;

        expect(campaignEventsRow($id)->status)->toBe('SCHEDULED');

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
        event(campaignEventsLive($id));

        $row = campaignEventsRow($id);
        CarbonImmutable::setTestNow(TestCase::FROZEN_AT);

        expect($row->status)->toBe('SENT')
            ->and($row->started_at)->not->toBeNull()
            ->and($row->send_at)->not->toBe($waitedFor)
            ->and(DB::table('ops.notices')->where('id', $id)->value('finished_at'))->not->toBeNull()
            ->and([$row->bell_count, $row->email_count])->toBe([2, 1])
            ->and(DB::table('ops.inbox_entries')->where('kind', 'offer_notice')->pluck('customer_id')->all())->toEqualCanonicalizing([$sara, $omar])
            ->and(DB::table('ops.inbox_entries')->where('customer_id', $elsewhere)->count())->toBe(0)
            ->and(CapturedEmails::installed()->to())->toBe(['sara@example.test']);
    });

    it('keeps a draft with no time, never sent by the scheduler, and starts it when its campaign goes live (§9.7 #1)', function () {
        Wx::agreeing('sara@example.test');
        $id = campaignEventsDraft();

        expect(campaignEventsRow($id)->status)->toBe('DRAFT')
            ->and(campaignEventsRow($id)->send_at)->toBeNull();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(30));
        app()->call([new StartDueNoticesJob, 'handle']);
        CarbonImmutable::setTestNow(TestCase::FROZEN_AT);

        expect(campaignEventsRow($id)->status)->toBe('DRAFT')
            ->and(campaignEventsNoticeBells())->toBe(0);

        event(campaignEventsLive($id));

        expect(campaignEventsRow($id)->status)->toBe('SENT')
            ->and(campaignEventsRow($id)->send_at)->not->toBeNull()
            ->and(campaignEventsNoticeBells())->toBe(1);
    });

    it('answers Content\'s port: the store\'s drafts newest first, and the notices it names with their status now', function () {
        Wx::agreeing('sara@example.test');
        Wx::agreeing('ae@example.test', 'ae');
        $older = campaignEventsDraft();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
        $newer = campaignEventsDraft();
        $scheduled = campaignEventsNotice();
        $elsewhere = campaignEventsDraft('ae');
        CarbonImmutable::setTestNow(TestCase::FROZEN_AT);
        $port = app(CampaignNotices::class);
        $sa = StoreId::fromString(Fx::storeId('sa'));

        expect(array_map(static fn (NoticeChoiceDto $notice): string => $notice->id, $port->drafts($sa, null, 10)))->toBe([$newer, $older])
            ->and(array_map(static fn (NoticeChoiceDto $notice): string => $notice->id, $port->drafts($sa, $newer, 10)))->toBe([$older])
            ->and($port->drafts($sa, $elsewhere, 10))->toBe([])
            ->and(array_map(static fn (NoticeChoiceDto $notice): string => $notice->status, $port->named($sa, [$scheduled, $elsewhere, $older])))->toEqualCanonicalizing(['SCHEDULED', 'DRAFT']);
    });

    it('cancels a draft as a waiting one, and the database holds a draft to having no time', function () {
        Wx::agreeing('sara@example.test');
        $id = campaignEventsDraft();
        app(CancelNoticeHandler::class)->handle(new CancelNotice($id));

        expect(campaignEventsRow($id)->status)->toBe('CANCELLED')
            ->and(campaignEventsRow($id)->send_at)->toBeNull();

        $draft = campaignEventsDraft();

        // NULL-safe both ways: a draft with a time, a waiting notice with none.
        expect(fn () => DB::transaction(fn () => DB::table('ops.notices')->where('id', $draft)->update(['send_at' => CarbonImmutable::now()])))->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => DB::table('ops.notices')->where('id', campaignEventsNotice())->update(['send_at' => null])))->toThrow(QueryException::class);
    });

    it('sends once: the same event again, another event, and the notice\'s own time all send nothing more', function () {
        Wx::agreeing('sara@example.test');
        $id = campaignEventsNotice();
        $event = campaignEventsLive($id);

        event($event);
        event($event);
        event(campaignEventsLive($id));

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(2));
        app()->call([new StartDueNoticesJob, 'handle']);
        CarbonImmutable::setTestNow(TestCase::FROZEN_AT);

        expect(campaignEventsNoticeBells())->toBe(1)
            ->and(CapturedEmails::installed()->sent)->toHaveCount(1)
            ->and(campaignEventsRow($id)->bell_count)->toBe(1)
            ->and(DB::table('ops.handled_events')->where('event_id', strtolower($event->eventId))->where('listener', StartCampaignNotice::class)->count())->toBe(1);
    });

    it('sends nothing for no notice, or for one that is gone', function (?string $noticeId) {
        Wx::agreeing('sara@example.test');

        event(campaignEventsLive($noticeId));

        expect(campaignEventsNoticeBells())->toBe(0)
            ->and(CapturedEmails::installed()->sent)->toBe([]);
    })->with(['no notice' => [null], 'a notice gone' => ['01jz0000000000000000000000']]);

    it('sends nothing for a cancelled notice', function () {
        Wx::agreeing('sara@example.test');
        $id = campaignEventsNotice();
        app(CancelNoticeHandler::class)->handle(new CancelNotice($id));

        event(campaignEventsLive($id));

        expect(campaignEventsRow($id)->status)->toBe('CANCELLED')
            ->and(campaignEventsRow($id)->started_at)->toBeNull()
            ->and(campaignEventsNoticeBells())->toBe(0)
            ->and(CapturedEmails::installed()->sent)->toBe([]);
    });

    it('sends nothing for a notice sending, sent or failed: only a waiting one starts', function (string $status) {
        Wx::agreeing('sara@example.test');
        $id = campaignEventsNotice();
        $now = CarbonImmutable::now()->toIso8601String();
        DB::table('ops.notices')->where('id', $id)->update(['status' => $status, 'started_at' => $now, 'finished_at' => $status === 'SENT' ? $now : null]);

        event(campaignEventsLive($id));

        expect(campaignEventsRow($id)->status)->toBe($status)
            ->and(campaignEventsRow($id)->bell_count)->toBe(0)
            ->and(campaignEventsNoticeBells())->toBe(0)
            ->and(CapturedEmails::installed()->sent)->toBe([]);
    })->with(['SENDING', 'SENT', 'FAILED']);

    it('sends nothing for another store\'s notice, logged by its ids alone, and leaves it waiting', function () {
        $log = Log::spy();
        Wx::agreeing('ae@example.test', 'ae');
        $id = campaignEventsNotice('ae');

        event(campaignEventsLive($id, 'sa'));

        expect(campaignEventsRow($id)->status)->toBe('SCHEDULED')
            ->and(campaignEventsNoticeBells())->toBe(0)
            ->and(CapturedEmails::installed()->sent)->toBe([]);
        $log->shouldHaveReceived('warning')->withArgs(static fn (string $message, array $context): bool => str_contains($message, 'another store') && ($context['notice_id'] ?? null) === $id);
    });
});

describe('how the events travel', function () {
    it('puts both listeners on the queue', function () {
        Queue::fake();

        event(campaignEventsExpiring());
        event(campaignEventsLive(null));

        Queue::assertPushed(CallQueuedListener::class, static fn (CallQueuedListener $job): bool => $job->class === AlertStaff::class);
        Queue::assertPushed(CallQueuedListener::class, static fn (CallQueuedListener $job): bool => $job->class === StartCampaignNotice::class);
    });

    it('dispatches after commit', function () {
        expect(campaignEventsExpiring())->toBeInstanceOf(ShouldDispatchAfterCommit::class)
            ->and(campaignEventsLive(null))->toBeInstanceOf(ShouldDispatchAfterCommit::class);
    });

    it('carries ids and moments alone through the outbox, and reaches both listeners whole', function () {
        campaignEventsContent(campaignEventsCampaign());
        $super = Fx::staff(superAdmin: true);
        Wx::agreeing('sara@example.test');
        $id = campaignEventsNotice();
        $expiring = campaignEventsExpiring();
        $live = campaignEventsLive($id);
        $none = campaignEventsLive(null);

        DB::transaction(function () use ($expiring, $live, $none): void {
            app(Outbox::class)->record($expiring);
            app(Outbox::class)->record($live);
            app(Outbox::class)->record($none);
        });

        $payload = static fn (CampaignExpiring|CampaignWentLive $event): array => array_keys((array) json_decode((string) DB::table('public.outbox_messages')->where('event_id', $event->eventId)->value('payload'), true));

        expect($payload($expiring))->toEqualCanonicalizing(['eventId', 'campaignId', 'storeId', 'endsAt', 'occurredAt'])
            ->and($payload($live))->toEqualCanonicalizing(['eventId', 'campaignId', 'storeId', 'noticeId', 'occurredAt']);

        app(OutboxRelay::class)->run();

        expect(DB::table('public.outbox_messages')->whereNull('dispatched_at')->count())->toBe(0)
            ->and(DB::table('ops.inbox_entries')->where('staff_id', $super)->value('line_en'))->toBe('The Ramadan Offers campaign in Saudi Arabia ends on 2026-12-01.')
            ->and(campaignEventsRow($id)->status)->toBe('SENT')
            ->and(campaignEventsNoticeBells())->toBe(1);
    });
});
