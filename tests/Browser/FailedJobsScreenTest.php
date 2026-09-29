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
| the number, and one job is retried and another deleted from the screen, the delete confirmed first.
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

    // The owner's words (2026-09-29), from the menu this person was offered.
    $page->assertSee('Failed jobs: 2 waiting')
        ->assertSeeIn('[data-test="count-platform.failed_jobs"]', '2')
        ->assertNoJavaScriptErrors();

    $page->click('[data-test="waiting-platform.failed_jobs"]')
        ->assertPathIs('/admin/failed-jobs')
        ->assertSee("Making an image's sizes")
        ->assertSee('RuntimeException: The disk did not answer.');

    $page->click("[data-test=\"retry-{$retried}\"]")
        ->assertSee('The job is back on its queue.')
        ->assertNoJavaScriptErrors();

    $page->click("[data-test=\"delete-{$deleted}\"]")
        ->assertSee('Delete this job for good? It will not run.')
        ->click("[data-test=\"delete-confirm-{$deleted}\"]")
        ->assertSee('The job was deleted.')
        ->assertSee('Nothing has failed.')
        ->assertNoJavaScriptErrors();

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1);

    DB::table('jobs')->delete();
});
