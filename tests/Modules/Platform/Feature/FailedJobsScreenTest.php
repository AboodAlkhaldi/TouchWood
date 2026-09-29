<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Platform\Public\PlatformPermissions;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| E7 over HTTP (frontend.md §3.5, platform.md §3): the list, one job, retry, delete — and the count
| beside the menu entry, which the admin home reads, only for those who may open the screen.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

function failedJobsScreenSignIn(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.11.3.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', [
        'code' => RecordingSecurityMessages::installed()->lastCode(),
    ])->assertRedirect('/admin');

    return $browser;
}

function failedJobsScreenAdmin(): AdminBrowser
{
    return failedJobsScreenSignIn(Fx::staffWith([PlatformPermissions::JOBS_MANAGE], ['sa'], RoleLevel::Admin));
}

/**
 * A failed job as Laravel's worker writes one: Platform's own image job, so it has a name.
 */
function failedJobsScreenFailed(string $connection = 'database', ?CarbonImmutable $failedAt = null): string
{
    $id = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $id,
        'connection' => $connection,
        'queue' => 'default',
        'payload' => json_encode([
            'uuid' => $id,
            'displayName' => 'Modules\Platform\Infrastructure\Queue\GenerateMediaVariantsJob',
            'maxTries' => 3,
            'data' => ['commandName' => 'Modules\Platform\Infrastructure\Queue\GenerateMediaVariantsJob', 'command' => 'O:8:"stdClass":0:{}'],
        ], JSON_THROW_ON_ERROR),
        'exception' => "RuntimeException: The disk did not answer.\n#0 /app/src/Something.php(12): run()\n#1 {main}",
        'failed_at' => $failedAt ?? now()->subHour(),
    ]);

    return $id;
}

/**
 * The form error a redirect came back with, if any.
 *
 * Read off the response's own session: AdminBrowser gives every request a fresh session store, so
 * the test case's own session never sees it.
 *
 * @param  TestResponse<Response>  $response
 */
function failedJobsScreenFormError(TestResponse $response): ?string
{
    $errors = AdminBrowser::flashed($response, 'errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->first('form');
    }

    // Once saved, a JSON session holds the bag as an array (session.serialization = json).
    $message = is_array($errors) ? ($errors['default']['messages']['form'][0] ?? null) : null;

    return is_string($message) ? $message : null;
}

/**
 * The failed jobs entry of the menu this person was sent, or null when they were offered none.
 *
 * @return array<string, mixed>|null
 */
function failedJobsScreenMenuEntry(AdminBrowser $browser, string $url = '/admin'): ?array
{
    $found = null;

    $browser->get($url)->assertOk()->assertInertia(function (AssertableInertia $inertia) use (&$found) {
        $menu = $inertia->toArray()['props']['menu'];

        foreach (is_array($menu) ? $menu : [] as $group) {
            foreach (is_array($group) && is_array($group['entries'] ?? null) ? $group['entries'] : [] as $entry) {
                if (is_array($entry) && ($entry['key'] ?? null) === 'failed_jobs') {
                    $found = [...$entry, 'group' => $group['key'] ?? null];
                }
            }
        }
    });

    return $found;
}

it('lists the failed jobs, each named in its module\'s words, and shows one with its whole error', function () {
    $id = failedJobsScreenFailed();
    $browser = failedJobsScreenAdmin();

    $browser->get('/admin/failed-jobs')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Platform/Admin/FailedJobs/Index')
        ->has('jobs', 1)
        ->where('jobs.0.id', $id)
        ->where('jobs.0.name', "Making an image's sizes")
        ->where('jobs.0.triesAllowed', 3)
        ->where('jobs.0.errorLine', 'RuntimeException: The disk did not answer.')
        ->where('jobs.0.retryable', true)
        ->where('nextFailedAt', null)
        ->where('nextId', null));

    $browser->get("/admin/failed-jobs/{$id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Platform/Admin/FailedJobs/Show')
        ->where('job.queue', 'default')
        ->where('job.retryable', true)
        ->where('error', fn (string $error): bool => str_contains($error, '#1 {main}')));
});

it('lists fifty at a time, oldest first, and the next page starts where the last ended', function () {
    $ids = [];

    for ($i = 0; $i < 51; $i++) {
        $ids[] = failedJobsScreenFailed(failedAt: CarbonImmutable::parse('2026-09-01 00:00:00')->addMinutes($i));
    }

    $browser = failedJobsScreenAdmin();
    $next = [];

    $browser->get('/admin/failed-jobs')->assertOk()->assertInertia(function (AssertableInertia $page) use ($ids, &$next) {
        $page->has('jobs', 50)
            ->where('jobs.0.id', $ids[0])
            ->where('jobs.49.id', $ids[49])
            ->where('nextId', $ids[49]);

        $next = ['after_at' => $page->toArray()['props']['nextFailedAt'], 'after_id' => $page->toArray()['props']['nextId']];
    });

    $browser->get('/admin/failed-jobs?'.http_build_query($next))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('jobs', 1)
        ->where('jobs.0.id', $ids[50])
        ->where('nextFailedAt', null)
        ->where('nextId', null));
});

it('offers no retry for a job that failed on another queue, and refuses one back where it was asked', function () {
    $id = failedJobsScreenFailed('sync');
    $browser = failedJobsScreenAdmin();

    $browser->get('/admin/failed-jobs')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('jobs.0.retryable', false));
    $browser->get("/admin/failed-jobs/{$id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('job.retryable', false));

    $refused = $browser->post("/admin/failed-jobs/{$id}/retry")->assertRedirect("/admin/failed-jobs/{$id}");

    expect(failedJobsScreenFormError($refused))->toBeIn([
        trans('platform::errors.failed_job_not_retryable.detail', [], 'en'),
        trans('platform::errors.failed_job_not_retryable.detail', [], 'ar'),
    ])
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('retries and deletes one, then lands on the list', function () {
    $retried = failedJobsScreenFailed();
    $deleted = failedJobsScreenFailed();
    $browser = failedJobsScreenAdmin();

    $browser->post("/admin/failed-jobs/{$retried}/retry")->assertRedirect('/admin/failed-jobs');
    $browser->post("/admin/failed-jobs/{$deleted}/delete")->assertRedirect('/admin/failed-jobs');

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('answers a job already handled as not found', function () {
    $id = failedJobsScreenFailed();
    $browser = failedJobsScreenAdmin();
    $browser->post("/admin/failed-jobs/{$id}/delete");

    $browser->get("/admin/failed-jobs/{$id}")->assertNotFound();
});

it('lands on the list, saying so, when somebody else handled the job the person was reading', function (string $action) {
    $id = failedJobsScreenFailed();
    $browser = failedJobsScreenAdmin();
    $browser->get("/admin/failed-jobs/{$id}")->assertOk();
    // Another admin, meanwhile.
    DB::table('failed_jobs')->where('uuid', $id)->delete();

    $response = $browser->post("/admin/failed-jobs/{$id}/{$action}")->assertRedirect('/admin/failed-jobs');

    expect(failedJobsScreenFormError($response))->toBeIn([
        trans('platform::errors.failed_job_not_found.detail', [], 'en'),
        trans('platform::errors.failed_job_not_found.detail', [], 'ar'),
    ]);
})->with(['retry', 'delete']);

it('shows the count beside the entry, in the System section, to those who may open it', function () {
    failedJobsScreenFailed();
    failedJobsScreenFailed();

    $entry = failedJobsScreenMenuEntry(failedJobsScreenAdmin());

    expect($entry['count'] ?? null)->toBe(2)
        ->and($entry['group'] ?? null)->toBe('system')
        ->and($entry['href'] ?? null)->toBe('/admin/failed-jobs');
});

it('gives no count to an entry that counts nothing', function () {
    $browser = failedJobsScreenSignIn(Fx::staff(superAdmin: true));

    $browser->get('/admin')->assertOk()->assertInertia(function (AssertableInertia $inertia) {
        $counts = [];

        foreach ($inertia->toArray()['props']['menu'] as $group) {
            foreach ($group['entries'] as $entry) {
                $counts[$entry['key']] = $entry['count'];
            }
        }

        // Keys looked up, not `??`-ed: a count of null must read as null, not as missing.
        expect($counts)->toHaveKeys(['audit', 'failed_jobs'])
            ->and($counts['audit'])->toBeNull()
            ->and($counts['failed_jobs'])->toBe(0);
    });
});

it('refuses, and offers no entry nor count to, somebody without the permission', function () {
    $id = failedJobsScreenFailed();
    $browser = failedJobsScreenSignIn(Fx::staffWith([PlatformPermissions::AUDIT_VIEW], ['*'], RoleLevel::Admin));

    expect(failedJobsScreenMenuEntry($browser))->toBeNull();

    $browser->get('/admin/failed-jobs')->assertForbidden();
    $browser->get("/admin/failed-jobs/{$id}")->assertForbidden();
    $browser->post("/admin/failed-jobs/{$id}/retry");
    $browser->post("/admin/failed-jobs/{$id}/delete");

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);
});
