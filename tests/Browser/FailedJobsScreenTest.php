<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| E7 in a real browser (frontend.md §3.5): the admin home says how many jobs failed, the menu shows
| the number - a dot when collapsed -, and one job is retried and another deleted from the screen,
| the delete confirmed first.
|
| No RefreshDatabase — the suite keeps its data — so the failed jobs start empty here and are left
| empty after.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

function failedJobsBrowserFailed(): string
{
    $id = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $id,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode([
            'uuid' => $id,
            'displayName' => 'Modules\Platform\Infrastructure\Queue\GenerateMediaVariantsJob',
            'maxTries' => 3,
            'data' => ['commandName' => 'Modules\Platform\Infrastructure\Queue\GenerateMediaVariantsJob', 'command' => 'O:8:"stdClass":0:{}'],
        ], JSON_THROW_ON_ERROR),
        'exception' => "RuntimeException: The disk did not answer.\n#0 {main}",
        'failed_at' => now()->subHour(),
    ]);

    return $id;
}

it('tells the admin on the home page, counts in the menu, and retries one job and deletes another', function () {
    DB::table('failed_jobs')->delete();
    DB::table('jobs')->delete();
    $retried = failedJobsBrowserFailed();
    $deleted = failedJobsBrowserFailed();

    $staffId = Fx::staffWith([PlatformPermissions::JOBS_MANAGE], ['sa'], RoleLevel::Admin);
    $email = (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->assertPathIs('/admin');

    // From the menu this person was offered: a row that opens the failed jobs, with its count
    // (owner, 2026-10-03: one row per waiting item, under one note - replacing the sentence of
    // 2026-09-29).
    $page->assertSeeIn('[data-test="waiting-platform.failed_jobs"]', 'Failed Jobs')
        ->assertSeeIn('[data-test="waiting-platform.failed_jobs"] [data-slot="badge"]', '2')
        ->assertSeeIn('[data-test="count-platform.failed_jobs"]', '2')
        ->assertMissing('[data-test="dot-platform.failed_jobs"]')
        ->assertNoJavaScriptErrors();

    // On the rail of icons the number has no room; a dot on the icon says something waits (owner,
    // 2026-09-29). Opened again after, as the rest of the test reads the full sidebar.
    $page->click('[data-sidebar="trigger"]')
        ->assertVisible('[data-test="dot-platform.failed_jobs"]')
        ->assertMissing('[data-test="count-platform.failed_jobs"]')
        ->click('[data-sidebar="trigger"]')
        ->assertVisible('[data-test="count-platform.failed_jobs"]')
        ->assertMissing('[data-test="dot-platform.failed_jobs"]');

    $page->click('[data-test="waiting-platform.failed_jobs"]')
        ->assertPathIs('/admin/failed-jobs')
        ->assertSee("Making an image's sizes")
        ->assertSee('RuntimeException: The disk did not answer.');

    $page->click("[data-test=\"retry-{$retried}\"]")
        ->assertSee('Job requeued')
        ->assertNoJavaScriptErrors();

    $page->click("[data-test=\"delete-{$deleted}\"]")
        ->assertSee('The job will not run. This cannot be undone.')
        ->click("[data-test=\"delete-confirm-{$deleted}\"]")
        ->assertSee('Job deleted')
        ->assertSee('Nothing has failed.')
        ->assertNoJavaScriptErrors();

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1);

    DB::table('jobs')->delete();
});
