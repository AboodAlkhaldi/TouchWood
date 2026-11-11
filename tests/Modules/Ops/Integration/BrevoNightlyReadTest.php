<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Access\Public\Events\MarketingConsentChanged;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Infrastructure\External\BrevoUnsubscribeRead;
use Modules\Ops\Infrastructure\Queue\ProviderFailureRules;
use Modules\Ops\Infrastructure\Queue\ReadBrevoListUnsubscribesJob;
use Modules\Ops\Infrastructure\Queue\ReadBrevoUnsubscribesJob;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\CapturedNewsletterList;
use Tests\Modules\Ops\Support\WrittenFixtures as Wx;

use function Pest\Laravel\seed;

/*
| The nightly read of Brevo's unsubscribes (ops.md §9.6 #1; owner, Content round 3 #17 and the answer (b) of
| 2026-11-10): each kept list's contacts changed since its last full read, as Brevo's reference shows the call,
| read 2026-11-11 (https://developers.brevo.com/reference/get-contacts-from-list): `GET /v3/contacts/lists/{listId}/contacts`
| with `modifiedSince`, `limit` (at most 500), `offset` and `sort`; each contact `email`, `emailBlacklisted`,
| `modifiedAt`, `listUnsubscribed`. A contact blacklisted, or with our list in `listUnsubscribed`, is taken as the
| webhook's unsubscribe. No real network: Http::fake answers, and refuses anything not faked.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

const BREVO_NIGHTLY_LIST = 41;

beforeEach(function () {
    seed(PlatformSeeder::class);
    RecordingSecurityMessages::install();
    CapturedNewsletterList::install();
    config([
        'ops.newsletter.driver' => 'brevo',
        'services.brevo.key' => 'the-nightly-key',
        'services.brevo.nightly_read' => true,
    ]);
    app()->instance('brevoNightlyRequests', new ArrayObject);
    brevoNightlyAnswers([]);
    Http::fake(function (Request $request) {
        // A customer's fixture asks the breached-passwords list: not Brevo, and not counted.
        if (! str_starts_with($request->url(), 'https://api.brevo.com/')) {
            return Http::response('');
        }

        $requests = app('brevoNightlyRequests');
        assert($requests instanceof ArrayObject);
        $requests->append($request);

        return (app('brevoNightlyAnswer'))($request, count($requests));
    });
    DB::table('ops.newsletter_lists')->insert(['store_id' => Fx::storeId('sa'), 'locale' => 'en', 'brevo_list_id' => BREVO_NIGHTLY_LIST, 'created_at' => '2026-11-01 09:30:00+00']);
});

/**
 * Brevo's pages, one answer a call in order; past the last, an empty page.
 *
 * @param  list<Closure|list<array<string, mixed>>>  $pages
 */
function brevoNightlyAnswers(array $pages): void
{
    app()->instance('brevoNightlyAnswer', static function (Request $request, int $call) use ($pages) {
        $page = $pages[$call - 1] ?? [];

        return $page instanceof Closure ? $page($request) : Http::response(['contacts' => $page, 'count' => count($page)]);
    });
}

/** @return list<Request> */
function brevoNightlyRequests(): array
{
    $requests = app('brevoNightlyRequests');
    assert($requests instanceof ArrayObject);

    return array_values($requests->getArrayCopy());
}

/** @return array<string, string> a request's query, as sent */
function brevoNightlyQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
    $sent = [];

    foreach ($query as $name => $value) {
        // Each of the read's parameters is one plain value; a list or a nested one would be no request of ours.
        expect($value)->toBeString();
        $sent[(string) $name] = is_string($value) ? $value : '';
    }

    return $sent;
}

/**
 * One of Brevo's contacts, as the reference shows it.
 *
 * @param  list<int>  $unsubscribed
 * @return array<string, mixed>
 */
function brevoNightlyContact(string $email, bool $blacklisted = false, array $unsubscribed = [], ?string $modifiedAt = '2026-11-10T20:00:00.000Z'): array
{
    return [
        'email' => $email, 'id' => random_int(1, 100000), 'emailBlacklisted' => $blacklisted, 'smsBlacklisted' => false,
        'createdAt' => '2026-11-02T10:00:00.000Z', 'listIds' => [BREVO_NIGHTLY_LIST], 'listUnsubscribed' => $unsubscribed,
        'attributes' => [], ...($modifiedAt === null ? [] : ['modifiedAt' => $modifiedAt]),
    ];
}

/** A confirmed guest row on the list's store, joined at that moment. */
function brevoNightlyGuest(string $email, string $joined = '2026-11-05 12:00:00+00', string $store = 'sa'): void
{
    DB::table('ops.subscribers')->insert([
        'id' => strtolower((string) Str::ulid()), 'store_id' => Fx::storeId($store), 'email' => $email, 'locale' => 'en', 'kind' => 'GUEST',
        'customer_id' => null, 'token_hash' => null, 'created_at' => $joined, 'confirmed_at' => $joined,
        'brevo_email' => $email, 'brevo_locale' => 'en', 'brevo_synced_at' => $joined,
    ]);
}

/** A customer who agreed to offers by email, on the list; their row joined at that moment. */
function brevoNightlyCustomer(string $email, string $joined = '2026-11-05 12:00:00+00'): string
{
    $id = Wx::agreeing($email);
    event(new MarketingConsentChanged((string) Str::uuid(), $id, CarbonImmutable::now()));
    DB::table('ops.subscribers')->where('customer_id', $id)->update(['created_at' => $joined, 'confirmed_at' => $joined]);

    return $id;
}

/** @return array<string, mixed> */
function brevoNightlyConsent(string $customerId): array
{
    return (array) DB::table('access.customers')->where('id', $customerId)->first(['marketing_consent', 'marketing_consent_source']);
}

function brevoNightlyRead(string $runStart = '2026-11-11T00:20:00+00:00'): void
{
    app(BrevoUnsubscribeRead::class)->read(BREVO_NIGHTLY_LIST, CarbonImmutable::parse($runStart)->toDateTimeImmutable());
}

function brevoNightlyReadAt(): ?string
{
    $at = DB::table('ops.newsletter_lists')->where('brevo_list_id', BREVO_NIGHTLY_LIST)->value('unsubscribes_read_at');

    return $at === null ? null : CarbonImmutable::parse((string) $at)->utc()->toIso8601String();
}

it('reads the list\'s pages as Brevo documents them: from an hour before the list was made, 500 at a time, oldest first, the key in its header', function () {
    $full = array_map(static fn (int $i): array => brevoNightlyContact("kept{$i}@example.test"), range(1, BrevoUnsubscribeRead::PAGE));
    brevoNightlyGuest('late@example.test');
    brevoNightlyAnswers([$full, [brevoNightlyContact('late@example.test', blacklisted: true)]]);

    brevoNightlyRead();

    $requests = brevoNightlyRequests();
    expect($requests)->toHaveCount(2);

    foreach ($requests as $i => $request) {
        expect($request->method())->toBe('GET')
            ->and(strtok($request->url(), '?'))->toBe('https://api.brevo.com/v3/contacts/lists/41/contacts')
            ->and($request->header('api-key'))->toBe(['the-nightly-key'])
            ->and(brevoNightlyQuery($request))->toBe(['modifiedSince' => '2026-11-01T08:30:00.000Z', 'limit' => '500', 'offset' => (string) ($i * 500), 'sort' => 'asc']);
    }

    // The second page's contact is taken: the walk went on past a full page.
    expect(DB::table('ops.subscribers')->where('email', 'late@example.test')->count())->toBe(0)
        ->and(CapturedNewsletterList::installed()->calls)->toBe(['remove late@example.test '.Fx::storeId('sa').' en']);
});

it('takes a blacklisted contact, and one that left our list, but not one that left another list only or is still subscribed', function () {
    foreach (['black', 'ours', 'other', 'still'] as $name) {
        brevoNightlyGuest("{$name}@example.test");
    }
    // The same email on another store's list: the webhook's path takes every row of it.
    brevoNightlyGuest('black@example.test', store: 'ae');
    brevoNightlyAnswers([[
        brevoNightlyContact('black@example.test', blacklisted: true),
        brevoNightlyContact('ours@example.test', unsubscribed: [7, BREVO_NIGHTLY_LIST]),
        brevoNightlyContact('other@example.test', unsubscribed: [7]),
        brevoNightlyContact('still@example.test'),
    ]]);

    brevoNightlyRead();

    expect(DB::table('ops.subscribers')->orderBy('email')->pluck('email')->all())->toBe(['other@example.test', 'still@example.test']);
});

it('withdraws a customer\'s consent through Access, and takes a guest\'s row off, as the webhook does', function () {
    $sara = brevoNightlyCustomer('sara@example.test');
    brevoNightlyGuest('guest@example.test');
    brevoNightlyAnswers([[brevoNightlyContact('sara@example.test', unsubscribed: [BREVO_NIGHTLY_LIST]), brevoNightlyContact('guest@example.test', blacklisted: true)]]);

    brevoNightlyRead();

    expect(brevoNightlyConsent($sara))->toBe(['marketing_consent' => false, 'marketing_consent_source' => 'NEWSLETTER_PROVIDER'])
        ->and(DB::table('ops.subscribers')->count())->toBe(0);
});

it('keeps the webhook\'s replay rule: a change older than the row\'s joining leaves it; a newer one, or none to read, takes it', function () {
    $sara = brevoNightlyCustomer('sara@example.test', '2026-11-10 12:00:00+00');
    brevoNightlyGuest('older@example.test', '2026-11-10 12:00:00+00');
    brevoNightlyGuest('newer@example.test', '2026-11-10 12:00:00+00');
    brevoNightlyGuest('untimed@example.test', '2026-11-10 12:00:00+00');
    brevoNightlyAnswers([[
        brevoNightlyContact('sara@example.test', blacklisted: true, modifiedAt: '2026-11-10T11:59:59.000Z'),
        brevoNightlyContact('older@example.test', blacklisted: true, modifiedAt: '2026-11-10T11:00:00.000Z'),
        brevoNightlyContact('newer@example.test', blacklisted: true, modifiedAt: '2026-11-10T12:00:01.000Z'),
        brevoNightlyContact('untimed@example.test', blacklisted: true, modifiedAt: null),
    ]]);

    brevoNightlyRead();

    expect(DB::table('ops.subscribers')->orderBy('email')->pluck('email')->all())->toBe(['older@example.test', 'sara@example.test'])
        ->and(brevoNightlyConsent($sara)['marketing_consent'])->toBeTrue();
});

it('keeps the read\'s start once the list is read to its end, and reads from an hour before it the next night', function () {
    brevoNightlyRead('2026-11-11T00:20:00+00:00');

    expect(brevoNightlyReadAt())->toBe('2026-11-11T00:20:00+00:00');

    // An older night's job, late from its waits, never moves the start back.
    brevoNightlyRead('2026-11-10T00:20:00+00:00');
    expect(brevoNightlyReadAt())->toBe('2026-11-11T00:20:00+00:00');

    brevoNightlyRead('2026-11-12T00:20:00+00:00');

    expect(array_map(static fn (Request $request): string => brevoNightlyQuery($request)['modifiedSince'], brevoNightlyRequests()))
        ->toBe(['2026-11-01T08:30:00.000Z', '2026-11-10T23:20:00.000Z', '2026-11-10T23:20:00.000Z'])
        ->and(brevoNightlyReadAt())->toBe('2026-11-12T00:20:00+00:00');
});

it('takes nothing and keeps no start when a page fails, for the queue to read the list again', function (Closure $answer, string $error) {
    brevoNightlyGuest('first@example.test');
    $full = [brevoNightlyContact('first@example.test', blacklisted: true), ...array_map(static fn (int $i): array => brevoNightlyContact("kept{$i}@example.test"), range(2, BrevoUnsubscribeRead::PAGE))];
    brevoNightlyAnswers([$full, $answer]);

    expect(fn () => brevoNightlyRead())->toThrow(ProviderFailed::class, $error);

    expect(brevoNightlyRequests())->toHaveCount(2)
        ->and(DB::table('ops.subscribers')->count())->toBe(1)
        ->and(brevoNightlyReadAt())->toBeNull();
})->with([
    'a 500' => [fn () => fn () => Http::response(['code' => 'internal_error', 'message' => 'x'], 500), 'Brevo refused the list\'s contacts: HTTP 500 (internal_error).'],
    'no connection' => [fn () => fn () => throw new ConnectionException('down'), 'Brevo: the API could not be reached.'],
    'a proxy\'s page' => [fn () => fn () => Http::response('<html>Please wait</html>', 200), 'Brevo refused the list\'s contacts: HTTP 200.'],
    'a page that is no list of contacts' => [fn () => fn () => Http::response(['contacts' => 'x', 'count' => 1]), 'Brevo answered the list\'s contacts with what it does not read: HTTP 200.'],
]);

it('stops at its limit of pages, takes what it read, keeps the old start and says so in the log naming no address', function () {
    brevoNightlyGuest('first@example.test');
    $full = static fn (int $page): array => array_map(static fn (int $i): array => $page === 1 && $i === 1
        ? brevoNightlyContact('first@example.test', blacklisted: true)
        : brevoNightlyContact("kept{$page}-{$i}@example.test"), range(1, BrevoUnsubscribeRead::PAGE));
    brevoNightlyAnswers(array_map($full, range(1, BrevoUnsubscribeRead::MOST_PAGES + 1)));
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$logged): void {
        if ($log->level === 'error') {
            $logged[] = $log->message.json_encode($log->context);
        }
    });

    brevoNightlyRead();

    expect(brevoNightlyRequests())->toHaveCount(BrevoUnsubscribeRead::MOST_PAGES)
        ->and(brevoNightlyQuery(brevoNightlyRequests()[BrevoUnsubscribeRead::MOST_PAGES - 1])['offset'])->toBe((string) ((BrevoUnsubscribeRead::MOST_PAGES - 1) * 500))
        ->and(DB::table('ops.subscribers')->count())->toBe(0)
        ->and(brevoNightlyReadAt())->toBeNull()
        ->and($logged)->toHaveCount(1)
        ->and($logged[0])->toContain('stopped at its limit of 15 pages of 500 on Brevo list 41')
        ->and($logged[0])->not->toContain('@');
});

it('reads nothing more of a list Brevo no longer has, and keeps no start', function () {
    brevoNightlyAnswers([fn () => Http::response(['code' => 'document_not_found', 'message' => 'List ID not found'], 404)]);

    brevoNightlyRead();

    expect(brevoNightlyRequests())->toHaveCount(1)
        ->and(brevoNightlyReadAt())->toBeNull();
});

it('runs each night at 03:20 Riyadh time on one server, and queues one read per list kept, carrying the moment it was queued', function () {
    $read = collect(app(Schedule::class)->events())->filter(static fn ($event): bool => $event->description === ReadBrevoUnsubscribesJob::class)->values();
    $night = $read->first();
    assert($night instanceof ScheduledEvent);

    expect($read)->toHaveCount(1)
        ->and([$night->expression, $night->timezone, $night->onOneServer])->toBe(['20 3 * * *', 'Asia/Riyadh', true]);

    DB::table('ops.newsletter_lists')->insert(['store_id' => Fx::storeId('sa'), 'locale' => 'ar', 'brevo_list_id' => 42, 'created_at' => now()]);
    Bus::fake([ReadBrevoListUnsubscribesJob::class]);

    (new ReadBrevoUnsubscribesJob)->handle(config(), app(BrevoUnsubscribeRead::class));

    $queued = [];
    Bus::assertDispatched(ReadBrevoListUnsubscribesJob::class, function (ReadBrevoListUnsubscribesJob $job) use (&$queued): bool {
        $queued[] = [$job->listId, $job->runAt];

        return true;
    });
    expect($queued)->toBe([[41, CarbonImmutable::now()->toIso8601String()], [42, CarbonImmutable::now()->toIso8601String()]])
        ->and(brevoNightlyRequests())->toBe([]);
});

it('reads each list through the queue, end to end, and keeps the night\'s moment as its start', function () {
    brevoNightlyGuest('guest@example.test');
    brevoNightlyAnswers([[brevoNightlyContact('guest@example.test', blacklisted: true)]]);

    ReadBrevoUnsubscribesJob::dispatch('2026-11-11T00:20:00+00:00');

    expect(brevoNightlyRequests())->toHaveCount(1)
        ->and(DB::table('ops.subscribers')->count())->toBe(0)
        ->and(brevoNightlyReadAt())->toBe('2026-11-11T00:20:00+00:00');
});

it('calls nothing while switched off, or while the newsletter is not Brevo\'s - a list job queued before it was switched off included', function (array $off) {
    brevoNightlyGuest('guest@example.test');
    brevoNightlyAnswers([[brevoNightlyContact('guest@example.test', blacklisted: true)]]);
    config($off);

    ReadBrevoUnsubscribesJob::dispatch();
    ReadBrevoListUnsubscribesJob::dispatch(BREVO_NIGHTLY_LIST, CarbonImmutable::now()->toIso8601String());

    expect(brevoNightlyRequests())->toBe([])
        ->and(DB::table('ops.subscribers')->count())->toBe(1)
        ->and(brevoNightlyReadAt())->toBeNull();
})->with([
    'off' => [['services.brevo.nightly_read' => false]],
    'unset' => [['services.brevo.nightly_read' => null]],
    'the log driver' => [['ops.newsletter.driver' => 'log']],
]);

it('is off by default, in config and in .env.example', function () {
    $services = require base_path('config/services.php');

    expect($services['brevo']['nightly_read'])->toBeFalse()
        ->and((string) file_get_contents(base_path('.env.example')))->toContain("\nBREVO_NIGHTLY_READ=false\n");
});

it('retries a list\'s read as the list jobs are, one list at a time, and carries neither the key nor an address', function () {
    $defaults = (new ReflectionClass(ReadBrevoListUnsubscribesJob::class))->getDefaultProperties();
    $job = new ReadBrevoListUnsubscribesJob(BREVO_NIGHTLY_LIST, '2026-11-11T00:20:00+00:00');

    expect(ProviderFailureRules::LIST_JOBS)->toContain(ReadBrevoListUnsubscribesJob::class)
        ->and([$defaults['tries'], $defaults['maxExceptions'], $defaults['backoff']])->toBe([0, ProviderFailureRules::LIST_JOB_FAILURES, ProviderFailureRules::LIST_JOB_BACKOFF])
        ->and($job->middleware())->toEqual([new ProviderFailureRules])
        ->and($job->uniqueId())->toBe('41')
        ->and(serialize($job))->not->toContain('the-nightly-key')
        ->and(serialize(new ReadBrevoUnsubscribesJob))->not->toContain('the-nightly-key');
});
