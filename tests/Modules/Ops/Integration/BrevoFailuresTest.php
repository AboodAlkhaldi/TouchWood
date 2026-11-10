<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Enums\StaffNotificationTopic;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Access\Public\Events\MarketingConsentChanged;
use Modules\Ops\Application\Alerts\EventDeliveries;
use Modules\Ops\Application\Alerts\EventMessage;
use Modules\Ops\Application\Command\CreateNotice\CreateNotice;
use Modules\Ops\Application\Command\CreateNotice\CreateNoticeHandler;
use Modules\Ops\Application\Command\SendDailyReportEmail\SendDailyReportEmail;
use Modules\Ops\Application\Command\SendDailyReportEmail\SendDailyReportEmailHandler;
use Modules\Ops\Application\Command\SetDailyReportEmail\SetDailyReportEmail;
use Modules\Ops\Application\Command\SetDailyReportEmail\SetDailyReportEmailHandler;
use Modules\Ops\Application\Messages\EmailAttachment;
use Modules\Ops\Application\Messages\EmailSender;
use Modules\Ops\Application\Messages\NewsletterList;
use Modules\Ops\Application\Messages\OutgoingEmail;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Application\Messages\SmsSender;
use Modules\Ops\Application\Newsletter\NewsletterQueue;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Model\SendStatus;
use Modules\Ops\Domain\Model\SentMessage;
use Modules\Ops\Domain\Repository\SentMessages;
use Modules\Ops\Domain\Repository\Subscribers;
use Modules\Ops\Infrastructure\External\BrevoAnswers;
use Modules\Ops\Infrastructure\Files\LocalReportFiles;
use Modules\Ops\Infrastructure\Messages\OpsSecurityMessages;
use Modules\Ops\Infrastructure\Messages\SecurityFailureAlert;
use Modules\Ops\Infrastructure\Queue\DeliverDailyReportJob;
use Modules\Ops\Infrastructure\Queue\ProviderFailureRules;
use Modules\Ops\Infrastructure\Queue\RemoveNewsletterContactJob;
use Modules\Ops\Infrastructure\Queue\SendBroadcastEmailJob;
use Modules\Ops\Infrastructure\Queue\SendEventEmailJob;
use Modules\Ops\Infrastructure\Queue\SyncSubscriberJob;
use Modules\Ops\Public\Enums\MessageKind;
use Modules\Platform\Application\Command\RetryFailedJob\RetryFailedJob;
use Modules\Platform\Application\Command\RetryFailedJob\RetryFailedJobHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Actor;
use Shared\Domain\ValueObject\StoreId;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\DailyReportFixtures as Dx;
use Tests\Modules\Ops\Support\WrittenFixtures as Wx;

use function Pest\Laravel\call;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\seed;

/*
| Every way Brevo can fail, on every path that calls it (ops.md §7's table of failures). Through the real
| adapters and the real database queue, as the live system runs: Http::fake answers, and refuses anything
| not faked; a job's attempts, its row and the failed jobs table are read after a worker ran it.
|
| What is Brevo's and how it was checked: developers.brevo.com, read 2026-11-03 - the statuses
| (docs/how-it-works: 401 key, 402 credit, 403 permission, 429 limit), the 400 codes
| (reference/send-transac-email), the reset header (docs/limit-headers), and the webhook's retries
| (docs/retry-mechanism: only a 429 or no answer is tried again).
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    Http::preventStrayRequests();
    Exceptions::fake();
    RecordingSecurityMessages::install();
    config([
        'queue.default' => 'database',
        'ops.email.driver' => 'brevo',
        'ops.newsletter.driver' => 'brevo',
        'services.brevo.key' => 'the-brevo-key',
        'services.brevo.newsletter_folder' => '7',
        'services.brevo.webhook_secret' => 'webhook-secret',
        'mail.from.address' => 'support@touchwood.test',
        'mail.from.name' => 'TouchWood',
    ]);
    app()->instance('brevoFailuresCalls', new ArrayObject);
    // One fake, its answer swapped: Http::fake's stubs add up, and the first one always answers.
    Http::fake(function (Request $request, array $options) {
        // A customer's fixture asks the breached-passwords list: not Brevo, and not counted.
        if (! str_starts_with($request->url(), 'https://api.brevo.com/')) {
            return Http::response('');
        }

        $calls = app('brevoFailuresCalls');
        assert($calls instanceof ArrayObject);
        $calls->append(['request' => $request, 'options' => $options]);

        return (app('brevoFailuresAnswerNow'))($request, count($calls));
    });
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

dataset('brevo failures that pass', [
    'no connection, a DNS failure, a timeout' => [null, null, []],
    '500' => [500, ['code' => 'internal_error', 'message' => 'x'], []],
    '503' => [503, '<html>Service Unavailable</html>', []],
    '400 duplicate_request: "submitted too frequently"' => [400, ['code' => 'duplicate_request', 'message' => 'x'], []],
    'a proxy\'s 200 in HTML' => [200, '<html>Please wait</html>', []],
    'a proxy\'s 401 in HTML' => [401, '<html>Sign in</html>', []],
    'a proxy\'s 403 in HTML' => [403, '<html>Forbidden</html>', []],
    'a proxy\'s 404, JSON but not Brevo\'s' => [404, ['error' => 'not found'], []],
]);

dataset('brevo refuses one address', [
    'its documented example' => [400, ['code' => 'invalid_parameter', 'message' => 'Invalid email address'], []],
    'in other words' => [400, ['code' => 'invalid_parameter', 'message' => 'email is not valid in to'], []],
]);

dataset('brevo refuses everybody', [
    '400 words naming the sender' => [400, ['code' => 'invalid_parameter', 'message' => 'The sender email is not valid'], []],
    '400 invalid_parameter, about something else' => [400, ['code' => 'invalid_parameter', 'message' => 'subject is too long'], []],
    '400 a code it does not know' => [400, ['code' => 'account_under_validation', 'message' => 'x'], []],
    '401 a bad key, or this server\'s IP not authorised' => [401, ['code' => 'unauthorized', 'message' => 'Key not found'], []],
    '402 out of credit' => [402, ['code' => 'not_enough_credits', 'message' => 'x'], []],
    '403 not permitted' => [403, ['code' => 'permission_denied', 'message' => 'x'], []],
    '404 no such address at Brevo' => [404, ['message' => 'x'], []],
]);

dataset('every brevo failure', [
    'no connection' => [null, null, []],
    '400' => [400, ['code' => 'invalid_parameter', 'message' => 'email is not valid'], []],
    '401' => [401, ['code' => 'unauthorized', 'message' => 'x'], []],
    '402' => [402, ['code' => 'not_enough_credits', 'message' => 'x'], []],
    '403' => [403, ['code' => 'permission_denied', 'message' => 'x'], []],
    '404' => [404, ['code' => 'document_not_found', 'message' => 'x'], []],
    '429' => [429, ['code' => 'too_many_requests', 'message' => 'x'], []],
    '503' => [503, '', []],
    'a proxy\'s 200 in HTML' => [200, '<html>Please wait</html>', []],
]);

/**
 * Every call to Brevo answered by $answer, each kept with its options.
 *
 * @param  callable(Request, int): mixed  $answer
 */
function brevoFailuresFake(callable $answer): void
{
    app()->instance('brevoFailuresAnswerNow', $answer);
}

/** Brevo back: what it answers from now on, the calls counted afresh. */
function brevoFailuresAfresh(callable $answer): void
{
    app()->instance('brevoFailuresCalls', new ArrayObject);
    brevoFailuresFake($answer);
}

/** @param array<string, string> $headers */
function brevoFailuresAnswer(?int $status, mixed $body, array $headers = []): mixed
{
    return $status === null ? throw new ConnectionException('cURL error 28: timed out for https://api.brevo.com/v3/contacts/private-guest%40example.test') : Http::response($body, $status, $headers);
}

function brevoFailuresCalls(): int
{
    $calls = app('brevoFailuresCalls');
    assert($calls instanceof ArrayObject);

    return count($calls);
}

/** @return list<array{request: Request, options: array<string, mixed>}> */
function brevoFailuresCallsMade(): array
{
    $calls = app('brevoFailuresCalls');
    assert($calls instanceof ArrayObject);

    return array_values($calls->getArrayCopy());
}

/** The worker, one job at a time, every wait over: as if each retry's time had come. How many ran. */
function brevoFailuresWork(int $most = 30): int
{
    $runs = 0;

    while (DB::table('jobs')->count() > 0 && $runs < $most) {
        DB::table('jobs')->update(['available_at' => CarbonImmutable::now()->subSecond()->getTimestamp()]);
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default']);
        $runs++;
    }

    return $runs;
}

/** One run of the worker with the job's wait over; the seconds of the wait it left, or null when no job is left. */
function brevoFailuresStep(): ?int
{
    DB::table('jobs')->update(['available_at' => CarbonImmutable::now()->subSecond()->getTimestamp()]);
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default']);
    $at = DB::table('jobs')->value('available_at');

    return $at === null ? null : (int) $at - CarbonImmutable::now()->getTimestamp();
}

function brevoFailuresRetry(): void
{
    Fx::actAsStaff(Fx::staffWith([PlatformPermissions::JOBS_MANAGE], ['sa'], RoleLevel::Admin));

    foreach (DB::table('failed_jobs')->pluck('uuid') as $id) {
        app(RetryFailedJobHandler::class)->handle(new RetryFailedJob((string) $id));
    }
}

/** A staff alert's email, its row and job written; the row's id. */
function brevoFailuresEventEmail(): string
{
    $staff = Fx::staff(superAdmin: true);
    DB::table('jobs')->delete();
    app(EventDeliveries::class)->emailStaff(new EventMessage(MessageKind::GatewayKeyRejected, Fx::storeId('sa'), ['mode' => 'LIVE']), $staff);

    return (string) DB::table('ops.sent_messages')->where('staff_id', $staff)->value('id');
}

/** A notice to every customer of the Saudi store, by email; its id. */
function brevoFailuresNotice(): string
{
    Fx::actAsAdmin(['sa'], ['ops.notice.manage']);

    return app(CreateNoticeHandler::class)->handle(new CreateNotice(Fx::storeId('sa'), 'عرض', 'Offer', 'نص', 'Text', null, false, true, null));
}

function brevoFailuresNoticeRow(string $id): stdClass
{
    return DB::table('ops.notices')->where('id', $id)->first() ?? throw new LogicException('No such notice.');
}

/** A made daily report whose delivery alone waits on the queue; its id. */
function brevoFailuresDailyReport(): string
{
    seed(CatalogSeeder::class);
    Storage::fake(LocalReportFiles::DISK);
    Dx::fakeRenderer();
    $staff = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa']);
    Fx::actAsStaff($staff);
    $setting = app(SetDailyReportEmailHandler::class)->handle(new SetDailyReportEmail(Fx::storeId('sa'), true, '07:30'));
    Fx::actAs(Actor::system(Actor::staff($staff)));
    $report = app(SendDailyReportEmailHandler::class)->handle(new SendDailyReportEmail($setting->id, $setting->version, $setting->nextDueAt ?? new DateTimeImmutable));
    DB::table('jobs')->where('payload', 'not like', '%DeliverDailyReportJob%')->delete();

    return (string) $report?->id;
}

/** A confirmed guest the provider does not hold yet, its sync queued; the row's id. */
function brevoFailuresGuest(string $email = 'guest@example.test', string $locale = 'en'): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('ops.subscribers')->insert([
        'id' => $id, 'store_id' => Fx::storeId('sa'), 'email' => $email, 'locale' => $locale, 'kind' => 'GUEST',
        'customer_id' => null, 'token_hash' => null, 'created_at' => now(), 'confirmed_at' => now(),
    ]);
    app(NewsletterQueue::class)->sync($id);

    return $id;
}

function brevoFailuresList(int $id = 41): void
{
    DB::table('ops.newsletter_lists')->insert(['store_id' => Fx::storeId('sa'), 'locale' => 'en', 'brevo_list_id' => $id, 'created_at' => now()]);
}

function brevoFailuresPath(Request $request): string
{
    return (string) parse_url($request->url(), PHP_URL_PATH);
}

/**
 * @param  array<string, mixed>|string  $body
 * @return TestResponse<Response>
 */
function brevoFailuresWebhook(array|string $body, ?string $secret = 'webhook-secret'): TestResponse
{
    return call('POST', '/newsletter/brevo', [], [], [], ['CONTENT_TYPE' => 'application/json', ...($secret === null ? [] : ['HTTP_X_TOUCHWOOD_SECRET' => $secret])], is_string($body) ? $body : (string) json_encode($body));
}

/** @param list<EmailAttachment> $attachments */
function brevoFailuresEmail(array $attachments = []): OutgoingEmail
{
    return new OutgoingEmail('support@touchwood.test', 'TouchWood', 'sara@example.test', 'Sara Ali', 'Subject', '<p>Hello</p>', 'Hello', attachments: $attachments);
}

describe('a hung Brevo holds nobody for long', function () {
    it('bounds every call: a person\'s request and a worker wait seconds, a report\'s upload a little longer', function () {
        brevoFailuresFake(fn (Request $request) => Http::response(['messageId' => '<1@relay>', 'id' => 41], 201));

        app(EmailSender::class)->send(brevoFailuresEmail());
        app(EmailSender::class)->send(brevoFailuresEmail([new EmailAttachment('r.pdf', '%PDF')]));
        app(NewsletterList::class)->add('sara@example.test', StoreId::fromString(Fx::storeId('sa')), 'en');

        $options = array_column(brevoFailuresCallsMade(), 'options');

        foreach ([$options[0], $options[2], $options[3]] as $plain) {
            expect($plain['connect_timeout'])->toBeLessThanOrEqual(3)->and($plain['timeout'])->toBeLessThanOrEqual(10);
        }

        expect($options[1]['connect_timeout'])->toBeLessThanOrEqual(3)->and($options[1]['timeout'])->toBeLessThanOrEqual(30)
            // A worker's own default timeout is 60 s and the queue takes a job as dead at retry_after: never near.
            ->and($options[1]['timeout'])->toBeLessThan((int) config('queue.connections.database.retry_after'));
    });
});

describe('an event\'s email', function () {
    it('goes to the failed jobs at once when one address is refused, marked FAILED, one call', function (?int $status, mixed $body) {
        $row = brevoFailuresEventEmail();
        brevoFailuresFake(fn () => brevoFailuresAnswer($status, $body));

        brevoFailuresWork();

        expect(brevoFailuresCalls())->toBe(1)
            ->and(DB::table('ops.sent_messages')->where('id', $row)->value('status'))->toBe('FAILED')
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(1)
            ->and((string) DB::table('failed_jobs')->where('payload', 'like', '%SendEventEmailJob%')->value('exception'))->toContain("HTTP {$status}");
    })->with('brevo refuses one address');

    it('waits for a person when Brevo refuses everybody: no failed job, a long wait, and it goes by itself once mended', function (?int $status, mixed $body) {
        $row = brevoFailuresEventEmail();
        brevoFailuresFake(fn () => brevoFailuresAnswer($status, $body));

        $wait = brevoFailuresStep();

        expect(brevoFailuresCalls())->toBe(1)
            ->and(DB::table('ops.sent_messages')->where('id', $row)->value('status'))->toBe('QUEUED')
            ->and(DB::table('failed_jobs')->count())->toBe(0)
            ->and($wait)->toBeGreaterThan(ProviderFailureRules::PERSON_WAITS[0] - 5)->toBeLessThanOrEqual(ProviderFailureRules::PERSON_WAITS[0]);
        Exceptions::assertReported(ProviderFailed::class);

        brevoFailuresAfresh(fn () => Http::response(['messageId' => '<mended@relay>'], 201));
        brevoFailuresWork();

        expect(brevoFailuresCalls())->toBe(1)
            ->and(DB::table('ops.sent_messages')->where('id', $row)->first())->toMatchArray(['status' => 'SENT', 'provider_id' => '<mended@relay>'])
            ->and(DB::table('failed_jobs')->count())->toBe(0);
    })->with('brevo refuses everybody');

    it('gives up on a person after about a day, by the long waits, with its reason, and retried it still goes', function () {
        $row = brevoFailuresEventEmail();
        brevoFailuresFake(fn () => Http::response(['code' => 'not_enough_credits', 'message' => 'x'], 402));

        foreach (ProviderFailureRules::PERSON_WAITS as $wait) {
            expect(brevoFailuresStep())->toBeGreaterThan($wait - 5)->toBeLessThanOrEqual($wait);
        }

        expect(array_sum(ProviderFailureRules::PERSON_WAITS))->toBeGreaterThan(23 * 3600)->toBeLessThanOrEqual(24 * 3600)
            ->and(brevoFailuresStep())->toBeNull()
            ->and(brevoFailuresCalls())->toBe(count(ProviderFailureRules::PERSON_WAITS) + 1)
            ->and(DB::table('ops.sent_messages')->where('id', $row)->value('status'))->toBe('FAILED')
            ->and(DB::table('failed_jobs')->count())->toBe(1)
            ->and((string) DB::table('failed_jobs')->value('exception'))->toContain('HTTP 402 (not_enough_credits)');

        brevoFailuresAfresh(fn () => Http::response(['messageId' => '<late@relay>'], 201));
        brevoFailuresRetry();
        brevoFailuresWork();

        expect(DB::table('ops.sent_messages')->where('id', $row)->value('status'))->toBe('SENT');
    });

    it('is tried five times when Brevo may come back, QUEUED till the last, then FAILED', function (?int $status, mixed $body) {
        $row = brevoFailuresEventEmail();
        brevoFailuresFake(fn () => brevoFailuresAnswer($status, $body));

        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default']);
        expect(DB::table('ops.sent_messages')->where('id', $row)->value('status'))->toBe('QUEUED');

        brevoFailuresWork();

        expect(brevoFailuresCalls())->toBe(5)
            ->and(DB::table('ops.sent_messages')->where('id', $row)->value('status'))->toBe('FAILED')
            ->and(DB::table('failed_jobs')->count())->toBe(1);
    })->with('brevo failures that pass');

    it('waits between those tries by the job\'s own backoff, 10 s, 1, 5 and 30 minutes', function () {
        $row = brevoFailuresEventEmail();
        brevoFailuresFake(fn () => Http::response('', 503));

        foreach ([10, 60, 300, 1800] as $wait) {
            expect(brevoFailuresStep())->toBeGreaterThan($wait - 5)->toBeLessThanOrEqual($wait);
        }

        expect(brevoFailuresStep())->toBeNull()
            ->and(DB::table('ops.sent_messages')->where('id', $row)->value('status'))->toBe('FAILED');
    });

    it('waits out a 429 by Brevo\'s own reset header, within its limits, however odd the value', function (string $reset, int $wait) {
        brevoFailuresEventEmail();
        brevoFailuresFake(fn () => Http::response(['code' => 'too_many_requests', 'message' => 'x'], 429, ['x-sib-ratelimit-reset' => $reset]));

        expect(brevoFailuresStep())->toBeGreaterThan($wait - 5)->toBeLessThanOrEqual($wait)
            ->and(DB::table('failed_jobs')->count())->toBe(0);
    })->with([
        'as it says' => ['120', 120],
        'not a number: the usual wait' => ['soon', BrevoAnswers::USUAL_WAIT],
        'nothing left: the usual wait' => ['0', BrevoAnswers::USUAL_WAIT],
        'negative: the usual wait' => ['-5', BrevoAnswers::USUAL_WAIT],
        'under a second: the least' => ['0.2', BrevoAnswers::LEAST_WAIT],
        'past the most' => ['99999', BrevoAnswers::MOST_WAIT],
        'past an int\'s range' => ['1e19', BrevoAnswers::MOST_WAIT],
        'near a float\'s range' => ['1e308', BrevoAnswers::MOST_WAIT],
        'past a float\'s range' => ['1e400', BrevoAnswers::MOST_WAIT],
    ]);

    it('spends no attempt on a 429: twenty of them, then it goes', function () {
        $row = brevoFailuresEventEmail();
        brevoFailuresFake(fn (Request $request, int $nth) => $nth <= 20
            ? Http::response(['code' => 'too_many_requests', 'message' => 'x'], 429)
            : Http::response(['messageId' => '<1@relay>'], 201));

        brevoFailuresWork();

        expect(brevoFailuresCalls())->toBe(21)
            ->and(DB::table('ops.sent_messages')->where('id', $row)->value('status'))->toBe('SENT')
            ->and(DB::table('failed_jobs')->count())->toBe(0);
    });

    it('still ends: a 429 that never lifts is FAILED in the end, the reason kept, not "attempted too many times"', function () {
        $row = brevoFailuresEventEmail();
        brevoFailuresFake(fn () => Http::response(['code' => 'too_many_requests', 'message' => 'x'], 429));
        DB::table('jobs')->update(['attempts' => ProviderFailureRules::MOST_RATE_LIMITED - 1]);

        brevoFailuresWork();

        expect(brevoFailuresCalls())->toBe(1)
            ->and(DB::table('ops.sent_messages')->where('id', $row)->value('status'))->toBe('FAILED')
            ->and((string) DB::table('failed_jobs')->value('exception'))->toContain('HTTP 429');
    });

    it('after a long outage: retried from the failed jobs screen, it goes once and is SENT', function () {
        $row = brevoFailuresEventEmail();
        brevoFailuresFake(fn () => Http::response('', 503));
        brevoFailuresWork();
        expect(DB::table('ops.sent_messages')->where('id', $row)->value('status'))->toBe('FAILED');

        brevoFailuresAfresh(fn () => Http::response(['messageId' => '<after@relay>'], 201));
        brevoFailuresRetry();
        brevoFailuresWork();

        expect(brevoFailuresCalls())->toBe(1)
            ->and(DB::table('ops.sent_messages')->where('id', $row)->first())->toMatchArray(['status' => 'SENT', 'provider_id' => '<after@relay>']);
    });

    it('is at least once: a crash between Brevo\'s yes and the row sends again, then never a third', function () {
        $row = brevoFailuresEventEmail();
        brevoFailuresFake(fn () => Http::response(['messageId' => '<1@relay>'], 201));
        $real = app(SentMessages::class);
        app()->instance(SentMessages::class, new class($real) implements SentMessages
        {
            public int $marks = 0;

            public function __construct(private readonly SentMessages $real) {}

            public function record(SentMessage $message): void
            {
                $this->real->record($message);
            }

            public function status(string $id): ?SendStatus
            {
                return $this->real->status($id);
            }

            public function markSent(string $id, ?string $providerId): void
            {
                if (++$this->marks === 1) {
                    throw new RuntimeException('The crash.');
                }

                $this->real->markSent($id, $providerId);
            }

            public function markFailed(string $id): void
            {
                $this->real->markFailed($id);
            }

            public function forget(string $id): void
            {
                $this->real->forget($id);
            }
        });

        brevoFailuresWork();

        expect(brevoFailuresCalls())->toBe(2)
            ->and(DB::table('ops.sent_messages')->where('id', $row)->value('status'))->toBe('SENT');
    });
});

describe('what a 2xx may carry', function () {
    it('takes Brevo\'s 201 as sent whatever its body - sending again would double it - and keeps a usable id only', function (mixed $body, ?string $id) {
        brevoFailuresFake(fn () => Http::response($body, 201));

        expect(app(EmailSender::class)->send(brevoFailuresEmail()))->toBe($id);
    })->with([
        'no messageId' => [['ok' => true], null],
        'an empty body' => ['', null],
        'HTML' => ['<html>Created</html>', null],
        'a messageId that is no string' => [['messageId' => ['<1@relay>']], null],
        'an empty messageId' => [['messageId' => ''], null],
    ]);

    it('marks SENT with an id too long for its column, never failing and so sending it again', function () {
        $row = brevoFailuresEventEmail();
        brevoFailuresFake(fn () => Http::response(['messageId' => str_repeat('x', 300)], 201));

        brevoFailuresWork();

        expect(brevoFailuresCalls())->toBe(1)
            ->and(DB::table('ops.sent_messages')->where('id', $row)->first())->toMatchArray(['status' => 'SENT', 'provider_id' => null]);
    });

    it('takes a 202 and a 2xx carrying Brevo\'s messageId as sent, and a proxy\'s page as not', function () {
        brevoFailuresFake(fn (Request $request, int $nth) => match ($nth) {
            1 => Http::response(null, 202),
            2 => Http::response(['messageId' => '<2@relay>'], 200),
            default => Http::response('<html>Please wait</html>', 200),
        });

        expect(app(EmailSender::class)->send(brevoFailuresEmail()))->toBeNull()
            ->and(app(EmailSender::class)->send(brevoFailuresEmail()))->toBe('<2@relay>')
            ->and(fn () => app(EmailSender::class)->send(brevoFailuresEmail()))->toThrow(ProviderFailed::class, 'HTTP 200');
    });

    it('never reports what Brevo\'s refusal says beyond a plain code: an address in it stays out', function () {
        brevoFailuresFake(fn () => Http::response(['code' => 'bad sara@example.test', 'message' => 'sara@example.test'], 400));

        expect(fn () => app(EmailSender::class)->send(brevoFailuresEmail()))->toThrow(new ProviderFailed('Brevo refused the email: HTTP 400.'));
    });
});

describe('the key and the sender', function () {
    it('cleans what a paste leaves round the key', function (string $pasted) {
        config(['services.brevo.key' => $pasted]);
        brevoFailuresFake(fn () => Http::response(['messageId' => '<1@relay>'], 201));

        app(EmailSender::class)->send(brevoFailuresEmail());

        expect(brevoFailuresCallsMade()[0]['request']->header('api-key'))->toBe(['the-key']);
    })->with(['a space' => ' the-key ', 'a line end' => "the-key\n", 'a Windows line end' => "the-key\r\n", 'a tab' => "\tthe-key"]);

    it('calls nobody with a key that is blank or malformed, on every path, and names no key', function (string $key) {
        config(['services.brevo.key' => $key]);
        brevoFailuresList();
        brevoFailuresFake(fn () => Http::response([], 201));

        foreach ([
            fn () => app(EmailSender::class)->send(brevoFailuresEmail()),
            fn () => app(NewsletterList::class)->remove('sara@example.test', StoreId::fromString(Fx::storeId('sa')), 'en'),
            fn () => app(NewsletterList::class)->forget('sara@example.test'),
        ] as $call) {
            $said = '';

            try {
                $call();
            } catch (ProviderFailed $failed) {
                $said = $failed->getMessage();
            }

            expect($said)->toContain('BREVO_API_KEY')->and($said)->not->toContain('sib');
        }

        expect(brevoFailuresCalls())->toBe(0);
    })->with(['empty' => '', 'spaces only' => '   ', 'a space inside' => 'xkeysib abc', 'a line inside' => "xkeysib\nabc", 'not ASCII' => 'xkeysib‑abc']);

    it('holds a job with no usable key on a long wait, calling nobody, and it goes once the key is mended', function () {
        config(['services.brevo.key' => '']);
        $row = brevoFailuresEventEmail();
        brevoFailuresFake(fn () => Http::response(['messageId' => '<1@relay>'], 201));

        expect(brevoFailuresStep())->toBeGreaterThan(ProviderFailureRules::PERSON_WAITS[0] - 5)
            ->and(DB::table('ops.sent_messages')->where('id', $row)->value('status'))->toBe('QUEUED')
            ->and(DB::table('failed_jobs')->count())->toBe(0)
            ->and(brevoFailuresCalls())->toBe(0);

        config(['services.brevo.key' => 'the-brevo-key']);
        brevoFailuresWork();

        expect(DB::table('ops.sent_messages')->where('id', $row)->value('status'))->toBe('SENT')
            ->and(brevoFailuresCalls())->toBe(1);
    });

    it('calls nobody with no sender address, says which setting, and omits a blank sender name', function () {
        brevoFailuresFake(fn () => Http::response(['messageId' => '<1@relay>'], 201));

        expect(fn () => app(EmailSender::class)->send(new OutgoingEmail('', 'TouchWood', 'sara@example.test', null, 'S', '<p>H</p>', 'H')))->toThrow(ProviderFailed::class, 'MAIL_FROM_ADDRESS')
            ->and(brevoFailuresCalls())->toBe(0);

        app(EmailSender::class)->send(new OutgoingEmail('support@touchwood.test', '  ', 'sara@example.test', null, 'S', '<p>H</p>', 'H'));

        expect(brevoFailuresCallsMade()[0]['request']->data()['sender'])->toBe(['email' => 'support@touchwood.test']);
    });
});

describe('a notice\'s emails', function () {
    it('keeps a bad address to itself: the others go, one failure counted, once, with no second send on a retry', function () {
        foreach (['one@example.test', 'bad@example.test', 'three@example.test'] as $email) {
            Wx::agreeing($email);
        }
        DB::table('jobs')->delete();
        brevoFailuresFake(fn (Request $request) => ($request->data()['to'][0]['email'] ?? '') === 'bad@example.test'
            ? Http::response(['code' => 'invalid_parameter', 'message' => 'email is not valid'], 400)
            : Http::response(['messageId' => '<1@relay>'], 201));
        $notice = brevoFailuresNotice();

        brevoFailuresWork();
        $row = brevoFailuresNoticeRow($notice);
        $tries = collect(brevoFailuresCallsMade())->countBy(fn (array $call): string => $call['request']->data()['to'][0]['email'])->all();

        expect([$row->status, $row->email_count, $row->failed_count])->toBe(['SENT', 2, 1])
            ->and($tries)->toBe(['one@example.test' => 1, 'bad@example.test' => 1, 'three@example.test' => 1]);

        // The same address refused again from the failed jobs screen: still one failure.
        brevoFailuresRetry();
        brevoFailuresWork();
        $row = brevoFailuresNoticeRow($notice);

        expect([$row->email_count, $row->failed_count])->toBe([2, 1]);
    });

    it('after an outage, what went stays gone and what did not goes, each once', function () {
        foreach (['one@example.test', 'two@example.test'] as $email) {
            Wx::agreeing($email);
        }
        DB::table('jobs')->delete();
        // One goes before Brevo falls over.
        brevoFailuresFake(fn (Request $request) => ($request->data()['to'][0]['email'] ?? '') === 'one@example.test'
            ? Http::response(['messageId' => '<1@relay>'], 201)
            : Http::response('', 503));
        $notice = brevoFailuresNotice();
        brevoFailuresWork();
        $row = brevoFailuresNoticeRow($notice);
        expect([$row->email_count, $row->failed_count])->toBe([1, 1]);

        brevoFailuresAfresh(fn () => Http::response(['messageId' => '<2@relay>'], 201));
        Artisan::call('queue:retry', ['id' => ['all']]);
        brevoFailuresWork();
        $row = brevoFailuresNoticeRow($notice);

        expect([$row->email_count, $row->failed_count])->toBe([2, 0])
            ->and(array_map(fn (array $call): string => $call['request']->data()['to'][0]['email'], brevoFailuresCallsMade()))->toBe(['two@example.test']);
    });
});

describe('a notice\'s emails when Brevo refuses everybody', function () {
    it('does not drain or spin: one call each, none failed, all waiting; topped up, each goes once', function () {
        foreach (['one@example.test', 'two@example.test', 'three@example.test'] as $email) {
            Wx::agreeing($email);
        }
        DB::table('jobs')->delete();
        brevoFailuresFake(fn () => Http::response(['code' => 'not_enough_credits', 'message' => 'x'], 402));
        $notice = brevoFailuresNotice();

        // Worked until nothing is due, its waits left as they are.
        do {
            $due = DB::table('jobs')->where('available_at', '<=', CarbonImmutable::now()->getTimestamp())->exists();

            if ($due) {
                Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default']);
            }
        } while ($due);

        $row = brevoFailuresNoticeRow($notice);
        $soonest = (int) DB::table('jobs')->min('available_at') - CarbonImmutable::now()->getTimestamp();

        expect(brevoFailuresCalls())->toBe(3)
            ->and(DB::table('jobs')->count())->toBe(3)
            ->and($soonest)->toBeGreaterThan(ProviderFailureRules::PERSON_WAITS[0] - 5)
            ->and(DB::table('failed_jobs')->count())->toBe(0)
            ->and([$row->email_count, $row->failed_count])->toBe([0, 0]);

        brevoFailuresAfresh(fn () => Http::response(['messageId' => '<1@relay>'], 201));
        brevoFailuresWork();
        $row = brevoFailuresNoticeRow($notice);

        expect(brevoFailuresCalls())->toBe(3)
            ->and([$row->email_count, $row->failed_count])->toBe([3, 0])
            ->and(DB::table('failed_jobs')->count())->toBe(0);
    });

    it('stops counting a failure when a retry finds the customer has withdrawn: nothing is sent, nothing stays stuck', function () {
        $sara = Wx::agreeing('sara@example.test');
        DB::table('jobs')->delete();
        brevoFailuresFake(fn () => Http::response(['code' => 'invalid_parameter', 'message' => 'Invalid email address'], 400));
        $notice = brevoFailuresNotice();
        brevoFailuresWork();
        $row = brevoFailuresNoticeRow($notice);
        expect([$row->email_count, $row->failed_count])->toBe([0, 1]);

        Wx::set($sara, ['marketing_consent' => false]);
        brevoFailuresAfresh(fn () => Http::response(['messageId' => '<1@relay>'], 201));
        brevoFailuresRetry();
        brevoFailuresWork();
        $row = brevoFailuresNoticeRow($notice);

        expect(brevoFailuresCalls())->toBe(0)
            ->and([$row->email_count, $row->failed_count])->toBe([0, 0])
            ->and(DB::table('ops.sent_messages')->where('customer_id', $sara)->count())->toBe(0);
    });
});

describe('the daily report\'s email', function () {
    it('waits for a person when Brevo refuses everybody; the report stays, not marked emailed, and goes once mended', function (?int $status, mixed $body) {
        $report = brevoFailuresDailyReport();
        brevoFailuresFake(fn () => brevoFailuresAnswer($status, $body));

        expect(brevoFailuresStep())->toBeGreaterThan(ProviderFailureRules::PERSON_WAITS[0] - 5)
            ->and(brevoFailuresCalls())->toBe(1)
            ->and(DB::table('ops.daily_reports')->where('id', $report)->value('emailed_at'))->toBeNull()
            ->and(DB::table('failed_jobs')->count())->toBe(0);

        brevoFailuresFake(fn () => Http::response(['messageId' => '<1@relay>'], 201));
        brevoFailuresWork();

        expect(DB::table('ops.daily_reports')->where('id', $report)->value('emailed_at'))->not->toBeNull();
    })->with('brevo refuses everybody');

    it('goes to the failed jobs at once when the person\'s own address is refused; the report stays', function (?int $status, mixed $body) {
        $report = brevoFailuresDailyReport();
        brevoFailuresFake(fn () => brevoFailuresAnswer($status, $body));

        brevoFailuresWork();

        expect(brevoFailuresCalls())->toBe(1)
            ->and(DB::table('ops.daily_reports')->where('id', $report)->value('emailed_at'))->toBeNull()
            ->and(DB::table('failed_jobs')->count())->toBe(1);
    })->with('brevo refuses one address');

    it('rides out an outage as the other emails do, and retried from the failed jobs it goes once, with its file', function (?int $status, mixed $body) {
        $report = brevoFailuresDailyReport();
        brevoFailuresFake(fn () => brevoFailuresAnswer($status, $body));
        brevoFailuresWork();

        expect(brevoFailuresCalls())->toBe(5)
            ->and(DB::table('ops.daily_reports')->where('id', $report)->value('emailed_at'))->toBeNull()
            ->and(DB::table('ops.daily_reports')->count())->toBe(1);

        brevoFailuresAfresh(fn () => Http::response(['messageId' => '<1@relay>'], 201));
        brevoFailuresRetry();
        brevoFailuresWork();

        expect(brevoFailuresCalls())->toBe(1)
            ->and(brevoFailuresCallsMade()[0]['request']->data()['attachment'][0])->toHaveKeys(['name', 'content'])
            ->and(DB::table('ops.daily_reports')->where('id', $report)->value('emailed_at'))->not->toBeNull();
    })->with('brevo failures that pass');
});

describe('a security email, sent within the person\'s request', function () {
    beforeEach(function () {
        app()->forgetInstance(SecurityMessages::class);
        Fx::staff(superAdmin: true);
        CarbonImmutable::setTestNow('2026-11-03 10:10:00');
    });

    it('whatever Brevo does: goes to the caller as a provider failure, FAILED, S10 once, naming no address, key or link', function (?int $status, mixed $body) {
        brevoFailuresFake(fn () => brevoFailuresAnswer($status, $body));

        expect(fn () => app(OpsSecurityMessages::class)->passwordReset('sara@example.test', 'en', 'https://shop.test/reset/the-token'))->toThrow(ProviderFailed::class);

        $entries = DB::table('ops.inbox_entries')->where('kind', 'security_email_failed')->get();
        $words = (string) json_encode($entries);

        expect(DB::table('ops.sent_messages')->where('kind', 'security')->pluck('status')->all())->toBe(['FAILED'])
            ->and($entries)->toHaveCount(1)
            ->and($words)->not->toContain('sara')
            ->and($words)->not->toContain('the-brevo-key')
            ->and($words)->not->toContain('the-token')
            // One failure may be one bad address: the words announce no outage.
            ->and($entries->pluck('title_en')->all())->toBe(['A security email could not be sent'])
            ->and($entries->pluck('line_en')->all())->toBe(['If this repeats, check the email settings and the log.']);
    })->with('every brevo failure');

    it('tells them once an hour under a burst, every failure still recorded FAILED, and again the next hour', function () {
        brevoFailuresFake(fn () => Http::response(['code' => 'unauthorized', 'message' => 'x'], 401));
        $burst = function (int $times): void {
            for ($i = 0; $i < $times; $i++) {
                try {
                    app(OpsSecurityMessages::class)->passwordReset("p{$i}@example.test", 'en', 'https://shop.test/reset/t');
                } catch (ProviderFailed) {
                }
            }
        };

        $burst(5);
        expect(DB::table('ops.inbox_entries')->where('kind', 'security_email_failed')->count())->toBe(1)
            ->and(DB::table('ops.sent_messages')->where('kind', 'security')->where('status', 'FAILED')->count())->toBe(5);

        CarbonImmutable::setTestNow('2026-11-03 11:00:00');
        $burst(1);
        expect(DB::table('ops.inbox_entries')->where('kind', 'security_email_failed')->count())->toBe(2);
    });

    it('lands a person registering signed in, with Brevo down: the account stays, nobody sees an error page', function () {
        config(['session.driver' => 'database']);
        FakeBreachList::install();
        brevoFailuresFake(fn () => Http::response('', 503));

        (new AdminBrowser)->post('/sa/en/account/register', [
            'email' => 'new@example.test',
            'password' => Fx::CUSTOMER_PASSWORD,
            'first_name' => 'Noura',
            'last_name' => 'Saleh',
            'account_type' => 'individual',
            'locale' => 'en',
            'terms' => '1',
        ])->assertRedirect('/sa/en');

        expect(DB::table('access.customers')->where('email', 'new@example.test')->exists())->toBeTrue()
            ->and(DB::table('ops.sent_messages')->where('kind', 'security')->pluck('status')->all())->toBe(['FAILED']);
    });

    it('records a send that broke on the way as FAILED, not only Brevo\'s refusals', function () {
        config(['mail.from.address' => '']);
        brevoFailuresFake(fn () => Http::response(['messageId' => '<1@relay>'], 201));

        expect(fn () => app(OpsSecurityMessages::class)->passwordReset('sara@example.test', 'en', 'https://shop.test/reset/t'))->toThrow(ProviderFailed::class, 'MAIL_FROM_ADDRESS')
            ->and(brevoFailuresCalls())->toBe(0)
            ->and(DB::table('ops.sent_messages')->where('kind', 'security')->pluck('status')->all())->toBe(['FAILED']);
    });
});

describe('the newsletter box', function () {
    it('answers 503 with Brevo away or the key refused, keeps the sign-up, and records it failed', function (?int $status, mixed $body) {
        brevoFailuresFake(fn () => brevoFailuresAnswer($status, $body));

        $answer = postJson('/sa/en/newsletter', ['email' => 'guest@example.test'])->assertStatus(503);

        expect($answer->json('type'))->toBe('ops.newsletter_unavailable')
            ->and((string) $answer->getContent())->not->toContain('Brevo')
            ->and(DB::table('ops.subscribers')->where('email', 'guest@example.test')->exists())->toBeTrue()
            ->and(DB::table('ops.sent_messages')->where('kind', 'newsletter_confirmation')->pluck('status')->all())->toBe(['FAILED']);
    })->with([
        'no connection' => [null, null],
        '503' => [503, ''],
        '401' => [401, ['code' => 'unauthorized', 'message' => 'x']],
    ]);

    it('never records a send that broke on the way as SENT', function () {
        app()->instance(EmailSender::class, new class implements EmailSender
        {
            public function send(OutgoingEmail $email): ?string
            {
                throw new RuntimeException('Not a provider\'s refusal.');
            }
        });

        postJson('/sa/en/newsletter', ['email' => 'guest@example.test']);

        expect(DB::table('ops.sent_messages')->where('kind', 'newsletter_confirmation')->pluck('status')->all())->toBe(['FAILED']);
    });
});

describe('the sync to the lists', function () {
    it('makes a list once, however the contact\'s call fails and however often it is retried', function () {
        $row = brevoFailuresGuest();
        $contacts = 0;
        brevoFailuresFake(function (Request $request) use (&$contacts) {
            $path = brevoFailuresPath($request);

            if ($path === '/v3/contacts/lists') {
                return Http::response(['id' => 41], 201);
            }

            if ($path === '/v3/contacts') {
                return ++$contacts === 1 ? Http::response('', 503) : Http::response(['id' => 900], 201);
            }

            return Http::response(null, 204);
        });

        brevoFailuresWork();
        $lists = collect(brevoFailuresCallsMade())->filter(fn (array $call): bool => brevoFailuresPath($call['request']) === '/v3/contacts/lists')->count();

        expect($lists)->toBe(1)
            ->and(DB::table('ops.newsletter_lists')->pluck('brevo_list_id')->all())->toBe([41])
            ->and(DB::table('ops.subscribers')->where('id', $row)->value('brevo_email'))->toBe('guest@example.test');
    });

    it('during an outage leaves the row as it was, tries ten times, and catches up when retried', function (?int $status, mixed $body) {
        brevoFailuresList();
        $row = brevoFailuresGuest();
        brevoFailuresFake(fn () => brevoFailuresAnswer($status, $body));
        $before = (array) DB::table('ops.subscribers')->where('id', $row)->first();

        brevoFailuresWork();

        expect(brevoFailuresCalls())->toBe(ProviderFailureRules::LIST_JOB_FAILURES)
            ->and($before['brevo_email'])->toBeNull()
            ->and((array) DB::table('ops.subscribers')->where('id', $row)->first())->toBe($before)
            ->and(DB::table('failed_jobs')->count())->toBe(1);

        brevoFailuresFake(fn (Request $request) => $request->method() === 'PUT' ? Http::response(null, 204) : Http::response(['id' => 900], 201));
        brevoFailuresRetry();
        brevoFailuresWork();

        expect(DB::table('ops.subscribers')->where('id', $row)->value('brevo_email'))->toBe('guest@example.test');
    })->with('brevo failures that pass');

    it('fails a refused address at once, and its neighbours go on', function () {
        brevoFailuresList();
        $bad = brevoFailuresGuest('bad@example.test');
        $good = brevoFailuresGuest('good@example.test');
        brevoFailuresFake(function (Request $request) {
            if ($request->method() === 'PUT') {
                return Http::response(null, 204);
            }

            return ($request->data()['email'] ?? '') === 'bad@example.test' ? Http::response(['code' => 'invalid_parameter', 'message' => 'Invalid email address'], 400) : Http::response(['id' => 900], 201);
        });

        brevoFailuresWork();
        $tried = collect(brevoFailuresCallsMade())->filter(fn (array $call): bool => ($call['request']->data()['email'] ?? '') === 'bad@example.test')->count();

        expect($tried)->toBe(1)
            ->and(DB::table('ops.subscribers')->where('id', $bad)->value('brevo_email'))->toBeNull()
            ->and(DB::table('ops.subscribers')->where('id', $good)->value('brevo_email'))->toBe('good@example.test');
    });

    it('takes a proxy\'s page as no answer, on every call that changes a list', function () {
        brevoFailuresList();
        brevoFailuresFake(fn () => Http::response('<html>Please wait</html>', 200));
        $store = StoreId::fromString(Fx::storeId('sa'));

        foreach ([
            fn () => app(NewsletterList::class)->add('sara@example.test', $store, 'en'),
            fn () => app(NewsletterList::class)->remove('sara@example.test', $store, 'en'),
            fn () => app(NewsletterList::class)->allow('sara@example.test'),
            fn () => app(NewsletterList::class)->forget('sara@example.test'),
        ] as $call) {
            expect($call)->toThrow(ProviderFailed::class, 'HTTP 200');
        }
    });

    it('forgets a list gone at Brevo, and the next try makes a new one and puts the contact on it', function () {
        brevoFailuresList(41);
        $row = brevoFailuresGuest();
        $contacts = 0;
        brevoFailuresFake(function (Request $request) use (&$contacts) {
            return match (brevoFailuresPath($request)) {
                '/v3/contacts/lists' => Http::response(['id' => 42], 201),
                '/v3/contacts' => ++$contacts === 1 ? Http::response(['code' => 'document_not_found', 'message' => 'x'], 400) : Http::response(['id' => 900], 201),
                default => Http::response(null, 204),
            };
        });

        expect(brevoFailuresStep())->toBeLessThanOrEqual(10)
            ->and(DB::table('ops.newsletter_lists')->count())->toBe(0);

        brevoFailuresWork();
        $added = collect(brevoFailuresCallsMade())->filter(fn (array $call): bool => brevoFailuresPath($call['request']) === '/v3/contacts')->last() ?? throw new LogicException('No contact was added.');

        expect(DB::table('ops.newsletter_lists')->pluck('brevo_list_id')->all())->toBe([42])
            ->and($added['request']->data()['listIds'])->toBe([42])
            ->and(DB::table('ops.subscribers')->where('id', $row)->value('brevo_email'))->toBe('guest@example.test')
            ->and(DB::table('failed_jobs')->count())->toBe(0);
    });

    it('takes a contact deleted at Brevo by hand as a new one: added again, 404 on allowing it fine', function () {
        brevoFailuresList();
        $row = brevoFailuresGuest();
        brevoFailuresFake(fn (Request $request) => $request->method() === 'PUT' ? Http::response(['code' => 'document_not_found', 'message' => 'x'], 404) : Http::response(['id' => 900], 201));

        brevoFailuresWork();

        expect(DB::table('ops.subscribers')->where('id', $row)->value('brevo_email'))->toBe('guest@example.test');
    });
});

describe('the removal from a list', function () {
    it('counts a contact already gone as done: nothing fails, nothing is retried', function (int $status, mixed $body) {
        brevoFailuresList();
        brevoFailuresFake(fn () => Http::response($body, $status));

        app(NewsletterQueue::class)->remove('gone@example.test', StoreId::fromString(Fx::storeId('sa')), 'en');
        brevoFailuresWork();

        expect(brevoFailuresCalls())->toBe(1)->and(DB::table('failed_jobs')->count())->toBe(0);
    })->with([
        'the list gone: 404' => [404, ['code' => 'document_not_found', 'message' => 'List not found']],
        'in its failure list: 201' => [201, ['contacts' => ['success' => [], 'failure' => ['gone@example.test']]]],
        '400 "Contact email not found"' => [400, ['code' => 'Contact email not found', 'message' => 'x']],
        '400 document_not_found' => [400, ['code' => 'document_not_found', 'message' => 'x']],
    ]);

    it('counts a contact to delete that is gone as done', function () {
        brevoFailuresFake(fn () => Http::response(['code' => 'document_not_found', 'message' => 'x'], 404));

        app(NewsletterQueue::class)->remove('gone@example.test', StoreId::fromString(Fx::storeId('sa')), 'en', true);
        brevoFailuresWork();

        expect(brevoFailuresCalls())->toBe(1)->and(DB::table('failed_jobs')->count())->toBe(0);
    });

    it('does not take a proxy\'s 404 page for "gone": the job tries again as for any outage, and waits on the failed jobs', function () {
        brevoFailuresFake(fn () => Http::response('<html>Not Found</html>', 404));

        expect(fn () => app(NewsletterList::class)->forget('sara@example.test'))->toThrow(ProviderFailed::class, 'HTTP 404');

        brevoFailuresAfresh(fn () => Http::response('<html>Not Found</html>', 404));
        app(NewsletterQueue::class)->remove('sara@example.test', StoreId::fromString(Fx::storeId('sa')), 'en', true);
        brevoFailuresWork();

        expect(brevoFailuresCalls())->toBe(ProviderFailureRules::LIST_JOB_FAILURES)
            ->and(DB::table('failed_jobs')->count())->toBe(1);
    });

    it('waits on a refused key, and during an outage retries, then waits encrypted on the failed jobs screen', function () {
        brevoFailuresList();
        $store = StoreId::fromString(Fx::storeId('sa'));
        brevoFailuresFake(fn () => Http::response(['code' => 'unauthorized', 'message' => 'x'], 401));
        app(NewsletterQueue::class)->remove('private@example.test', $store, 'en');
        expect(brevoFailuresStep())->toBeGreaterThan(ProviderFailureRules::PERSON_WAITS[0] - 5)
            ->and(brevoFailuresCalls())->toBe(1)
            ->and(DB::table('failed_jobs')->count())->toBe(0);

        DB::table('jobs')->delete();
        DB::table('failed_jobs')->delete();
        brevoFailuresAfresh(fn () => Http::response('', 503));
        app(NewsletterQueue::class)->remove('private@example.test', $store, 'en');
        brevoFailuresWork();
        $failed = DB::table('failed_jobs')->first() ?? throw new LogicException('No failed job.');

        expect(brevoFailuresCalls())->toBe(ProviderFailureRules::LIST_JOB_FAILURES)
            ->and($failed->payload)->not->toContain('private@')
            ->and($failed->exception)->not->toContain('private@');

        brevoFailuresAfresh(fn () => Http::response(['contacts' => ['success' => ['private@example.test']]], 201));
        brevoFailuresRetry();
        brevoFailuresWork();

        expect(brevoFailuresCalls())->toBe(1);
    });
});

describe('consent during an outage', function () {
    it('holds in our database: the unsubscribe link works at once, and the list follows when Brevo is back', function () {
        $sara = Wx::agreeing('sara@example.test');
        brevoFailuresList();
        DB::table('ops.subscribers')->insert([
            'id' => strtolower((string) Str::ulid()), 'store_id' => Fx::storeId('sa'), 'email' => 'sara@example.test', 'locale' => 'en', 'kind' => 'CUSTOMER',
            'customer_id' => $sara, 'token_hash' => null, 'created_at' => now(), 'confirmed_at' => now(),
            'brevo_email' => 'sara@example.test', 'brevo_locale' => 'en', 'brevo_synced_at' => now(),
        ]);
        DB::table('jobs')->delete();
        brevoFailuresFake(fn () => Http::response('', 503));

        $path = URL::signedRoute('storefront.ops.unsubscribe', ['store' => 'sa', 'locale' => 'en', 'customer' => $sara], null, false);
        postJson($path)->assertOk();

        expect(DB::table('access.customers')->where('id', $sara)->value('marketing_consent'))->toBeFalse()
            ->and(brevoFailuresCalls())->toBe(0);

        brevoFailuresWork();
        expect(DB::table('access.customers')->where('id', $sara)->value('marketing_consent'))->toBeFalse()
            ->and(DB::table('ops.subscribers')->where('customer_id', $sara)->exists())->toBeFalse()
            ->and(DB::table('failed_jobs')->count())->toBe(1);

        // Back up, retried: off the list.
        brevoFailuresAfresh(fn () => Http::response(['contacts' => ['success' => ['sara@example.test']]], 201));
        brevoFailuresRetry();
        brevoFailuresWork();

        expect(array_map(fn (array $call): string => brevoFailuresPath($call['request']), brevoFailuresCallsMade()))->toBe(['/v3/contacts/lists/41/contacts/remove']);
    });
});

describe('Brevo\'s webhook', function () {
    it('refuses a wrong or missing secret, and acts on nothing', function (?string $secret) {
        $guest = brevoFailuresGuest();
        DB::table('jobs')->delete();

        brevoFailuresWebhook(['event' => 'unsubscribe', 'email' => 'guest@example.test'], $secret)->assertUnauthorized();

        expect(DB::table('ops.subscribers')->where('id', $guest)->exists())->toBeTrue();
    })->with(['wrong' => 'wrong', 'missing' => null]);

    it('takes a secret pasted into .env with stray whitespace', function () {
        config(['services.brevo.webhook_secret' => " webhook-secret\n"]);

        brevoFailuresWebhook(['event' => 'unsubscribe', 'email' => 'nobody@example.test'])->assertNoContent();
    });

    it('answers an unknown email and a replay with no change, so Brevo does not try again', function () {
        brevoFailuresGuest();
        DB::table('jobs')->delete();
        $body = ['event' => 'unsubscribe', 'email' => 'guest@example.test'];

        brevoFailuresWebhook(['event' => 'unsubscribe', 'email' => 'nobody@example.test'])->assertNoContent();
        expect(DB::table('ops.subscribers')->count())->toBe(1);

        brevoFailuresWebhook($body)->assertNoContent();
        brevoFailuresWebhook($body)->assertNoContent();
        expect(DB::table('ops.subscribers')->count())->toBe(0);
    });

    it('withdraws a customer\'s consent once, however often it is replayed', function () {
        $sara = Wx::agreeing('sara@example.test');
        DB::table('ops.subscribers')->insert([
            'id' => strtolower((string) Str::ulid()), 'store_id' => Fx::storeId('sa'), 'email' => 'sara@example.test', 'locale' => 'en', 'kind' => 'CUSTOMER',
            'customer_id' => $sara, 'token_hash' => null, 'created_at' => now(), 'confirmed_at' => now(),
        ]);
        DB::table('jobs')->delete();
        brevoFailuresFake(fn () => Http::response(null, 204));
        $body = ['event' => 'unsubscribe', 'email' => 'sara@example.test'];
        $changes = 0;
        Event::listen(MarketingConsentChanged::class, function () use (&$changes): void {
            $changes++;
        });

        brevoFailuresWebhook($body)->assertNoContent();
        brevoFailuresWebhook($body)->assertNoContent();
        brevoFailuresWork();

        expect(DB::table('access.customers')->where('id', $sara)->value('marketing_consent'))->toBeFalse()
            ->and($changes)->toBe(1)
            ->and(DB::table('failed_jobs')->count())->toBe(0);
    });

    it('leaves a row alone when the event is older than its joining: Brevo trying again never undoes a newer opt-in', function (string $kind) {
        $joined = CarbonImmutable::parse('2026-11-03 12:00:00');
        $sara = $kind === 'CUSTOMER' ? Wx::agreeing('sara@example.test') : null;
        DB::table('ops.subscribers')->insert([
            'id' => strtolower((string) Str::ulid()), 'store_id' => Fx::storeId('sa'), 'email' => 'sara@example.test', 'locale' => 'en', 'kind' => $kind,
            'customer_id' => $sara, 'token_hash' => null, 'created_at' => $joined, 'confirmed_at' => $joined,
        ]);
        DB::table('jobs')->delete();
        $body = ['event' => 'unsubscribe', 'email' => 'sara@example.test'];

        brevoFailuresWebhook([...$body, 'ts_event' => $joined->subHour()->getTimestamp()])->assertNoContent();

        expect(DB::table('ops.subscribers')->count())->toBe(1)
            ->and(DB::table('jobs')->count())->toBe(0);

        // Newer than the joining, or no time at all: it counts.
        brevoFailuresWebhook([...$body, ...($kind === 'CUSTOMER' ? ['ts_event' => $joined->addHour()->getTimestamp()] : [])])->assertNoContent();

        expect($kind === 'CUSTOMER' ? DB::table('jobs')->count() : DB::table('ops.subscribers')->count())->toBe($kind === 'CUSTOMER' ? 1 : 0);
    })->with(['a guest' => 'GUEST', 'a customer' => 'CUSTOMER']);

    it('answers what it does not read with 204, and leaves a line in the log naming no address', function (string $body) {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$logged): void {
            $logged[] = $log->message.json_encode($log->context);
        });

        brevoFailuresWebhook($body)->assertNoContent();

        expect(implode(' ', $logged))->toContain('Brevo')
            ->and(implode(' ', $logged))->not->toContain('sara');
    })->with([
        'not JSON' => '<html>sara@example.test</html>',
        'a batch, a list of events' => '[{"event":"unsubscribe","email":"sara@example.test"}]',
        'no email' => '{"event":"unsubscribe"}',
        'an email that is not one' => '{"event":"unsubscribe","email":"sara"}',
    ]);

    it('asks Brevo to try again when our side breaks: 429, the only answer it retries - a 500 is thrown away', function () {
        $subscribers = Mockery::mock(Subscribers::class);
        $subscribers->shouldReceive('withEmail')->andThrow(new RuntimeException('The database is away.'));
        app()->instance(Subscribers::class, $subscribers);

        brevoFailuresWebhook(['event' => 'unsubscribe', 'email' => 'sara@example.test'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        Exceptions::assertReported(RuntimeException::class);
    });

    it('still takes a call while the site is in maintenance: a 503 would be thrown away', function () {
        $guest = brevoFailuresGuest();
        DB::table('jobs')->delete();
        app()->maintenanceMode()->activate([]);

        try {
            brevoFailuresWebhook(['event' => 'unsubscribe', 'email' => 'guest@example.test'])->assertNoContent();
            // Only this address: a person's pages still answer the maintenance page.
            get('/sa/en')->assertStatus(503);
        } finally {
            app()->maintenanceMode()->deactivate();
        }

        expect(DB::table('ops.subscribers')->where('id', $guest)->exists())->toBeFalse();
    });
});

describe('what stays secret', function () {
    it('leaves the key, and any address, out of every log, exception and failed job, whatever fails', function (?int $status, mixed $body) {
        ini_set('zend.exception_ignore_args', '0');
        config(['services.brevo.key' => 'xkeysib-the-real-key']);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$logged): void {
            $logged[] = $log->message.json_encode($log->context).(($log->context['exception'] ?? null) instanceof Throwable ? (string) $log->context['exception'] : '');
        });
        brevoFailuresList();
        brevoFailuresEventEmail();
        brevoFailuresGuest('private-guest@example.test');
        app(NewsletterQueue::class)->remove('private-gone@example.test', StoreId::fromString(Fx::storeId('sa')), 'en');
        app(NewsletterQueue::class)->remove('private-forgot@example.test', StoreId::fromString(Fx::storeId('sa')), 'en', true);
        brevoFailuresFake(fn () => brevoFailuresAnswer($status, $body));

        brevoFailuresWork(40);
        app()->forgetInstance(SecurityMessages::class);

        try {
            app(OpsSecurityMessages::class)->passwordReset('private-reset@example.test', 'en', 'https://shop.test/reset/t');
        } catch (ProviderFailed $failed) {
            $logged[] = (string) $failed;
        }

        try {
            postJson('/sa/en/newsletter', ['email' => 'private-box@example.test']);
        } catch (Throwable) {
        }

        $everything = implode("\n", $logged).DB::table('failed_jobs')->get()->map(fn (stdClass $job): string => $job->payload.$job->exception)->implode("\n");

        expect(DB::table('failed_jobs')->count())->toBe(4)
            ->and($everything)->not->toContain('xkeysib')
            ->and($everything)->not->toContain('private-');
        Exceptions::assertNotReported(fn (Throwable $reported): bool => str_contains((string) $reported, 'xkeysib') || str_contains((string) $reported, 'private-'));
    })->with([
        'no connection' => [null, null],
        '503' => [503, ''],
        '401' => [401, ['code' => 'unauthorized', 'message' => 'Key not found: xkeysib-the-real-key']],
        '400' => [400, ['code' => 'invalid_parameter', 'message' => 'email private-guest@example.test is not valid']],
    ]);
});

describe('every SMS adapter', function () {
    it('marks the phone and the message itself: PHP does not pass the attribute down from the interface', function () {
        $checked = 0;

        foreach (glob(base_path('src/Modules/Ops/Infrastructure/External/*.php')) ?: [] as $file) {
            $class = 'Modules\\Ops\\Infrastructure\\External\\'.basename($file, '.php');

            if (! is_subclass_of($class, SmsSender::class)) {
                continue;
            }

            foreach ((new ReflectionMethod($class, 'send'))->getParameters() as $parameter) {
                expect($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1);
            }

            $checked++;
        }

        expect($checked)->toBeGreaterThan(0);
    });
});

describe('ops:brevo:check, the real token\'s check', function () {
    /**
     * @param  array<string, mixed>  $senders
     * @param  array<string, mixed>  $domains
     */
    function brevoFailuresCheck(array $senders, array $domains, int $account = 200, int $folder = 200): int
    {
        brevoFailuresFake(function (Request $request) use ($senders, $domains, $account, $folder) {
            return match (brevoFailuresPath($request)) {
                '/v3/account' => Http::response($account === 200 ? ['email' => 'owner@touchwood.test', 'companyName' => 'TouchWood', 'plan' => [['type' => 'free', 'credits' => 300, 'creditsType' => 'sendLimit']]] : ['code' => 'unauthorized', 'message' => 'x'], $account),
                '/v3/senders' => Http::response($senders),
                '/v3/senders/domains' => Http::response($domains),
                '/v3/contacts/folders/7' => Http::response($folder === 200 ? ['id' => 7, 'name' => 'Newsletter'] : ['message' => 'Folder ID not found'], $folder),
                default => Http::response('', 500),
            };
        });

        return Artisan::call('ops:brevo:check');
    }

    it('passes on a working key, an active sender and the folder, reads only, prints no key, and ends on queue:restart', function () {
        $code = brevoFailuresCheck(
            ['senders' => [['id' => 1, 'name' => 'T', 'email' => 'Support@touchwood.test', 'active' => true]]],
            ['domains' => [['id' => 'd', 'domain_name' => 'touchwood.test', 'authenticated' => true, 'verified' => true, 'ip' => null]]],
        );
        $output = Artisan::output();

        expect($code)->toBe(0)
            ->and($output)->not->toContain('the-brevo-key')
            ->and($output)->toContain('support@touchwood.test')->toContain('active')->toContain('folder 7')
            ->and(trim($output))->toEndWith('running workers hold the old configuration.')
            ->and(trim($output))->toContain('php artisan queue:restart')
            ->and(array_unique(array_map(fn (array $call): string => $call['request']->method(), brevoFailuresCallsMade())))->toBe(['GET'])
            ->and(array_map(fn (array $call): string => brevoFailuresPath($call['request']), brevoFailuresCallsMade()))->toBe(['/v3/account', '/v3/senders', '/v3/contacts/folders/7', '/v3/senders/domains']);
    });

    it('fails on a sender that is inactive or not there', function (array $senders) {
        expect(brevoFailuresCheck(['senders' => $senders], ['domains' => []]))->toBe(1)
            ->and(Artisan::output())->toContain('support@touchwood.test');
    })->with([
        'inactive' => [[['id' => 1, 'name' => 'T', 'email' => 'support@touchwood.test', 'active' => false]]],
        'not there' => [[['id' => 1, 'name' => 'T', 'email' => 'other@touchwood.test', 'active' => true]]],
    ]);

    it('fails on a folder Brevo does not have, or none set, and still says how the sender stands', function () {
        $senders = ['senders' => [['id' => 1, 'name' => 'T', 'email' => 'support@touchwood.test', 'active' => true]]];

        expect(brevoFailuresCheck($senders, ['domains' => []], folder: 404))->toBe(1);
        $output = Artisan::output();
        expect($output)->toContain('BREVO_NEWSLETTER_FOLDER_ID')->toContain('is active at Brevo');

        config(['services.brevo.newsletter_folder' => '']);
        brevoFailuresAfresh(fn () => Http::response([], 200));
        expect(brevoFailuresCheck($senders, ['domains' => []]))->toBe(1)
            ->and(Artisan::output())->toContain('BREVO_NEWSLETTER_FOLDER_ID');
    });

    it('warns on a domain not authenticated, without failing: Brevo may still send', function () {
        $code = brevoFailuresCheck(
            ['senders' => [['id' => 1, 'name' => 'T', 'email' => 'support@touchwood.test', 'active' => true]]],
            ['domains' => [['id' => 'd', 'domain_name' => 'touchwood.test', 'authenticated' => false, 'verified' => false, 'ip' => null]]],
        );

        expect($code)->toBe(0)->and(Artisan::output())->toContain('not authenticated');
    });

    it('fails on a refused key, says what to look at, and asks nothing more', function () {
        expect(brevoFailuresCheck([], [], 401))->toBe(1)
            ->and(Artisan::output())->toContain('401')->toContain('Authorized IPs')
            ->and(brevoFailuresCalls())->toBe(1);
    });

    it('fails on no answer, and calls nobody with a key that cannot be one', function () {
        brevoFailuresFake(fn () => throw new ConnectionException('down'));
        expect(Artisan::call('ops:brevo:check'))->toBe(1)->and(Artisan::output())->toContain('could not be reached');

        brevoFailuresAfresh(fn () => Http::response([], 200));
        config(['services.brevo.key' => 'two words']);
        $code = Artisan::call('ops:brevo:check');
        $output = Artisan::output();

        expect($code)->toBe(1)
            ->and($output)->toContain('BREVO_API_KEY')->toContain('queue:restart')
            ->and($output)->not->toContain('two words')
            ->and(brevoFailuresCalls())->toBe(0);
    });
});

/** The jobs on the queue worked until none is due, their waits left as they are. At most $most runs. */
function brevoFailuresWorkDue(int $most = 20): void
{
    for ($runs = 0; $runs < $most && DB::table('jobs')->where('available_at', '<=', CarbonImmutable::now()->getTimestamp())->exists(); $runs++) {
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default']);
    }
}

/**
 * S10c's entries, every column of each, oldest first.
 *
 * @return list<array<string, mixed>>
 */
function brevoFailuresQueuedAlerts(): array
{
    return array_values(array_map(static fn (object $row): array => (array) $row, DB::table('ops.inbox_entries')->where('kind', 'queued_email_refused')->orderBy('created_at')->orderBy('staff_id')->get()->all()));
}

/** @return list<string> */
function brevoFailuresQueuedAlertOwners(): array
{
    $owners = array_map('strval', array_column(brevoFailuresQueuedAlerts(), 'staff_id'));
    sort($owners);

    return $owners;
}

/**
 * Every active Super Admin, by id: whom S10c reaches.
 *
 * @return list<string>
 */
function brevoFailuresSuperAdmins(): array
{
    $ids = [];

    foreach (app(AccessApi::class)->staffToAlert(StoreId::fromString(Fx::storeId('sa')), StaffNotificationTopic::NewOrders, OpsPermissions::SMS_COUNTRIES_MANAGE) as $staff) {
        $ids[] = $staff->staffId;
    }

    sort($ids);

    return $ids;
}

dataset('queued emails', [
    'an event\'s email' => [function (): void {
        brevoFailuresEventEmail();
    }],
    'a notice\'s email' => [function (): void {
        Wx::agreeing('one@example.test');
        DB::table('jobs')->delete();
        brevoFailuresNotice();
    }],
    'the daily report\'s email' => [function (): void {
        brevoFailuresDailyReport();
    }],
]);

describe('the list jobs, which last about a day (owner, Content round 3 #19)', function () {
    it('waits 10 s, 1, 5 and 30 minutes, 1, 2, 4, 8 and 8 hours between its tries, then the failed jobs', function (Closure $queue) {
        brevoFailuresList();
        $queue();
        brevoFailuresFake(fn () => Http::response('', 503));

        foreach ([10, 60, 300, 1800, 3600, 7200, 14400, 28800, 28800] as $wait) {
            expect(brevoFailuresStep())->toBeGreaterThan($wait - 5)->toBeLessThanOrEqual($wait);
        }

        expect(brevoFailuresStep())->toBeNull()
            ->and(brevoFailuresCalls())->toBe(10)
            ->and(DB::table('failed_jobs')->count())->toBe(1)
            ->and(array_sum(ProviderFailureRules::LIST_JOB_BACKOFF))->toBeGreaterThan(23 * 3600)->toBeLessThanOrEqual(24 * 3600);
    })->with([
        'the sync' => [fn () => brevoFailuresGuest()],
        'the removal' => [fn () => app(NewsletterQueue::class)->remove('gone@example.test', StoreId::fromString(Fx::storeId('sa')), 'en')],
    ]);

    it('leaves the email jobs as they were: five failures over about 36 minutes', function () {
        foreach ([SendEventEmailJob::class, SendBroadcastEmailJob::class, DeliverDailyReportJob::class] as $job) {
            $defaults = (new ReflectionClass($job))->getDefaultProperties();

            expect([$defaults['maxExceptions'], $defaults['backoff']])->toBe([5, [10, 60, 300, 1800]], $job);
        }

        foreach ([SyncSubscriberJob::class, RemoveNewsletterContactJob::class] as $job) {
            $defaults = (new ReflectionClass($job))->getDefaultProperties();

            expect([$defaults['maxExceptions'], $defaults['backoff']])->toBe([10, [10, 60, 300, 1800, 3600, 7200, 14400, 28800, 28800]], $job);
        }
    });
});

describe('the bell when Brevo refuses a queued email for everybody (S10c; owner, Content round 3 #20)', function () {
    it('tells every active Super Admin once, the bell alone, whatever their switches; no Admin, nobody inactive', function (Closure $queue) {
        $silenced = Fx::staff(superAdmin: true);

        foreach (StaffNotificationTopic::cases() as $topic) {
            DB::table('access.staff_notification_preferences')->insert(['staff_user_id' => $silenced, 'topic' => $topic->value, 'email' => false, 'panel' => false]);
        }

        $inactive = Fx::staff(StaffStatus::Disabled, superAdmin: true);
        $admin = Fx::staffWith(['ops.notice.manage', 'ops.message.send_many', 'ops.report.daily'], ['sa', 'ae'], RoleLevel::Admin);
        $queue();
        brevoFailuresFake(fn () => Http::response(['code' => 'not_enough_credits', 'message' => 'x'], 402));

        brevoFailuresWorkDue();

        expect(brevoFailuresQueuedAlertOwners())->toBe(brevoFailuresSuperAdmins())
            ->and(brevoFailuresQueuedAlertOwners())->toContain($silenced)
            ->and(brevoFailuresQueuedAlertOwners())->not->toContain($inactive)
            ->and(brevoFailuresQueuedAlertOwners())->not->toContain($admin)
            // The refused email's one call: the alert sends no email, queues nothing, and records no message.
            ->and(brevoFailuresCalls())->toBe(1)
            ->and(DB::table('ops.sent_messages')->where('kind', 'queued_email_refused')->count())->toBe(0)
            // The email still waits for a person, as before.
            ->and(DB::table('jobs')->count())->toBe(1)
            ->and(DB::table('failed_jobs')->count())->toBe(0);
    })->with('queued emails');

    it('says queued emails wait and what to check, in both languages, with no link and nothing of the email or the key', function () {
        brevoFailuresEventEmail();
        brevoFailuresFake(fn () => Http::response(['code' => 'unauthorized', 'message' => 'Key not found'], 401));

        brevoFailuresStep();

        $entry = brevoFailuresQueuedAlerts()[0];
        $written = (string) json_encode($entry, JSON_UNESCAPED_UNICODE);

        expect([$entry['title_en'], $entry['line_en'], $entry['title_ar'], $entry['line_ar']])->toBe([
            'Queued emails are waiting',
            'The email provider refused to send them. They are tried again for up to a day: check the email key, credit and sender, and the log.',
            'رسائل بريد إلكتروني بانتظار الإرسال',
            'رفض مزوّد البريد الإلكتروني إرسالها. ستُعاد محاولة إرسالها لمدة تصل إلى يوم: راجع مفتاح البريد الإلكتروني ورصيده والمرسل، والسجل.',
        ])
            ->and($entry['link'])->toBeNull()
            ->and($entry['read_at'])->toBeNull()
            // Filed under the first store: the refusal is the provider's, every store's.
            ->and($entry['store_id'])->toBe(app(PlatformApi::class)->allStores()[0]->id)
            ->and($written)->not->toContain('the-brevo-key')
            ->and($written)->not->toContain('@')
            ->and($written)->not->toContain('Key not found');
    });

    it('holds to one entry a clock hour under a burst of refusals, and writes the next in the next hour', function () {
        CarbonImmutable::setTestNow('2026-11-10 10:10:00');

        foreach (['one@example.test', 'two@example.test', 'three@example.test'] as $email) {
            Wx::agreeing($email);
        }

        Fx::staff(superAdmin: true);
        DB::table('jobs')->delete();
        brevoFailuresFake(fn () => Http::response(['code' => 'not_enough_credits', 'message' => 'x'], 402));
        brevoFailuresNotice();

        brevoFailuresWorkDue();

        expect(brevoFailuresCalls())->toBe(3)
            ->and(brevoFailuresQueuedAlertOwners())->toBe(brevoFailuresSuperAdmins());

        CarbonImmutable::setTestNow('2026-11-10 10:59:59');
        brevoFailuresStep();
        expect(brevoFailuresQueuedAlertOwners())->toBe(brevoFailuresSuperAdmins());

        CarbonImmutable::setTestNow('2026-11-10 11:10:00');
        brevoFailuresStep();
        expect(brevoFailuresQueuedAlerts())->toHaveCount(2 * count(brevoFailuresSuperAdmins()));
    });

    it('has its own hour: a security email failing in the same hour tells them of each', function () {
        CarbonImmutable::setTestNow('2026-11-10 10:10:00');
        brevoFailuresEventEmail();
        brevoFailuresFake(fn () => Http::response(['code' => 'not_enough_credits', 'message' => 'x'], 402));

        brevoFailuresStep();
        expect(fn () => app(OpsSecurityMessages::class)->passwordReset('sara@example.test', 'en', 'https://shop.test/reset/the-token'))->toThrow(ProviderFailed::class);

        expect(brevoFailuresQueuedAlerts())->toHaveCount(count(brevoFailuresSuperAdmins()))
            ->and(DB::table('ops.inbox_entries')->where('kind', 'security_email_failed')->count())->toBe(count(brevoFailuresSuperAdmins()));
    });

    it('raises nothing for a list job, an outage that may pass, or one address refused', function () {
        Fx::staff(superAdmin: true);
        brevoFailuresList();
        brevoFailuresFake(fn () => Http::response(['code' => 'unauthorized', 'message' => 'x'], 401));
        brevoFailuresGuest();
        app(NewsletterQueue::class)->remove('gone@example.test', StoreId::fromString(Fx::storeId('sa')), 'en');
        brevoFailuresWorkDue();

        // Both list jobs met the refusal and wait for a person, telling nobody: they send no email.
        expect(brevoFailuresCalls())->toBe(2)
            ->and(DB::table('jobs')->count())->toBe(2)
            ->and(brevoFailuresQueuedAlerts())->toBe([]);

        DB::table('jobs')->delete();
        brevoFailuresEventEmail();
        brevoFailuresAfresh(fn () => Http::response('', 503));
        brevoFailuresWork();
        brevoFailuresEventEmail();
        brevoFailuresAfresh(fn () => Http::response(['code' => 'invalid_parameter', 'message' => 'Invalid email address'], 400));
        brevoFailuresWork();

        expect(DB::table('failed_jobs')->count())->toBe(2)
            ->and(brevoFailuresQueuedAlerts())->toBe([]);
    });

    it('never changes what happens to the email: a failure writing it is reported, the email still waits, and the next refusal tells them', function () {
        CarbonImmutable::setTestNow('2026-11-10 10:10:00');
        brevoFailuresEventEmail();
        // A real refusal from the database, not a stand-in.
        DB::statement("ALTER TABLE ops.inbox_entries ADD CONSTRAINT refuse_the_alert CHECK (kind <> 'queued_email_refused')");
        brevoFailuresFake(fn () => Http::response(['code' => 'not_enough_credits', 'message' => 'x'], 402));

        $wait = brevoFailuresStep();

        Exceptions::assertReported(static fn (QueryException $failed): bool => str_contains($failed->getMessage(), 'refuse_the_alert'));
        expect($wait)->toBeGreaterThan(ProviderFailureRules::PERSON_WAITS[0] - 5)->toBeLessThanOrEqual(ProviderFailureRules::PERSON_WAITS[0])
            ->and(DB::table('failed_jobs')->count())->toBe(0)
            ->and(brevoFailuresQueuedAlerts())->toBe([]);

        // The claim went with the failed write, so the next refusal in the same hour tries again.
        DB::statement('ALTER TABLE ops.inbox_entries DROP CONSTRAINT refuse_the_alert');
        brevoFailuresStep();

        expect(brevoFailuresQueuedAlertOwners())->toBe(brevoFailuresSuperAdmins());
    });

    it('costs a refusal after the first two statements of its own', function () {
        Fx::staff(superAdmin: true);
        app(SecurityFailureAlert::class)->queuedEmailRefused();
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(SecurityFailureAlert::class)->queuedEmailRefused();

        $queries = array_map(static fn (array $query): string => (string) $query['query'], DB::getQueryLog());
        DB::disableQueryLog();

        // Measured 2026-11-10: the wait's limit, and the claim that finds the hour taken.
        expect($queries)->toHaveCount(2)
            ->and($queries[0])->toContain('lock_timeout')
            ->and($queries[1])->toContain('handled_events');
    });
});
