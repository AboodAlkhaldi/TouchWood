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
| The media library and the audit log, in a real browser (frontend.md §3.5, E5 and E6).
|
| The tests beside these prove what each screen is handed. These prove a person can use them: that
| the library switches between a table and a grid, and that the log draws with its filters.
*/

const LIBRARY_SCREEN_PASSWORD = 'a long enough password';

// Deliberately no RefreshDatabase: the suite serves the application in this process, and a served
// request opens its own connection, so the two would deadlock.

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/**
 * A new Super Admin's email address, for signing in through the real screens.
 *
 * The sign-in itself is written out in each test rather than returned from here: the page object
 * the browser plugin hands back is not the type its own signature promises, so a helper that
 * returned it would have to lie about what it returns.
 */
function libraryScreenEmail(): string
{
    return (string) DB::table('access.staff_users')
        ->where('id', Fx::staff(superAdmin: true))
        ->value('email');
}

/**
 * One file in the library, written straight in: these tests are about the screen, not the upload.
 */
function libraryScreenFileNamed(string $filename, string $variantsStatus): string
{
    $id = strtolower((string) Str::ulid());

    DB::table('platform.media')->insert([
        'id' => $id,
        'visibility' => 'PUBLIC',
        'disk' => 'public',
        'object_key' => 'media/'.$id.'.jpg',
        'original_filename' => $filename,
        'mime' => 'image/jpeg',
        'bytes' => 2_400_000,
        // Its own, because identical public files are stored once (platform.md §1.4).
        'checksum' => hash('sha256', $id),
        'variants_status' => $variantsStatus,
        'variants_queued_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $id;
}

it('draws the media library and switches between the table and the grid', function () {
    $filename = 'photo-'.Str::random(6).'.jpg';
    libraryScreenFileNamed($filename, 'PENDING');

    $page = visit('/admin/sign-in')
        ->type('#email', libraryScreenEmail())
        ->type('#password', LIBRARY_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->assertPathIs('/admin')
        ->navigate('/admin/media');

    $page->assertSee('Media Library')
        ->assertSee($filename)
        // No thumbnail for an image still being processed: Platform has nothing to give yet.
        ->assertSee('Being Processed')
        ->assertNoJavaScriptErrors();

    $page->click('[data-test="view-switch"]')
        ->assertSee($filename)
        ->assertNoJavaScriptErrors();
});

it('asks before deleting a file, in the page, and then deletes it', function () {
    $filename = 'doomed-'.Str::random(6).'.jpg';
    $id = libraryScreenFileNamed($filename, 'READY');

    $page = visit('/admin/sign-in')
        ->type('#email', libraryScreenEmail())
        ->type('#password', LIBRARY_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->assertPathIs('/admin')
        ->navigate('/admin/media');

    // Asked in the page, so a test can answer it. The browser's own confirm box could not be
    // driven at all, which is why this went uncovered and then went wrong (owner, 2026-09-24).
    $page->click("[data-test=\"delete-{$id}\"]")
        ->assertSee('Nothing uses this file.')
        ->assertNoJavaScriptErrors();

    expect(DB::table('platform.media')->where('id', $id)->exists())->toBeTrue();

    $page->click("[data-test=\"delete-confirm-{$id}\"]")
        ->assertDontSee($filename)
        ->assertNoJavaScriptErrors();

    expect(DB::table('platform.media')->where('id', $id)->exists())->toBeFalse();
});

/**
 * A company's paper in the library, written straight in, marked as having sizes so that only the
 * private rule keeps a picture off the screen.
 */
function libraryScreenPaperNamed(string $filename): string
{
    $id = strtolower((string) Str::ulid());

    DB::table('platform.media')->insert([
        'id' => $id,
        'visibility' => 'PRIVATE',
        'disk' => 'local',
        'object_key' => 'media/'.$id.'.pdf',
        'original_filename' => $filename,
        'mime' => 'application/pdf',
        'bytes' => 2_400_000,
        'checksum' => hash('sha256', $id),
        'variants_status' => 'READY',
        'variants_queued_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $id;
}

/**
 * The day a file was uploaded: the first ten characters of what the database gives back. The screen
 * writes the moment in the store's time and in words ("2m ago"), so a test reads it from the
 * <time> element's datetime, which is the moment itself (frontend.md 1.10, store time).
 */
function libraryScreenShownDate(string $mediaId): string
{
    return substr((string) DB::table('platform.media')->where('id', $mediaId)->value('created_at'), 0, 10);
}

it('shows a private file in the table as its name, date and use, with no picture, type or size, and Describe and Delete for a Super Admin', function () {
    $paper = 'paper-'.Str::random(8).'.pdf';
    $photo = 'photo-'.Str::random(8).'.jpg';
    $paperId = libraryScreenPaperNamed($paper);
    $photoId = libraryScreenFileNamed($photo, 'READY');
    $date = libraryScreenShownDate($paperId);

    $page = visit('/admin/sign-in')
        ->type('#email', libraryScreenEmail())
        ->type('#password', LIBRARY_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->assertPathIs('/admin')
        ->navigate('/admin/media');

    // A Super Admin holds every permission, so they may describe and delete it, as the public file
    // beside it (amendment 8(a)).
    $page->assertSee($paper)
        ->assertPresent("[data-test=\"describe-{$paperId}\"]")
        ->assertPresent("[data-test=\"delete-{$paperId}\"]")
        ->assertPresent("[data-test=\"describe-{$photoId}\"]")
        ->assertPresent("[data-test=\"delete-{$photoId}\"]")
        // Its own row: the name, the date and where it is used - no picture, no type, no size, and
        // exactly the two buttons.
        ->assertScript(<<<JS
            (() => {
                const row = [...document.querySelectorAll('tbody tr')].find((tr) => tr.textContent.includes('{$paper}'));
                return row !== undefined
                    && (row.querySelector('time')?.getAttribute('datetime') ?? '').startsWith('{$date}')
                    && row.textContent.includes('Not used')
                    && ! row.textContent.includes('application/pdf')
                    && ! row.textContent.includes('2.3 MB')
                    && ! row.textContent.includes('Ready')
                    && row.querySelector('img') === null
                    && row.querySelectorAll('button').length === 2;
            })()
            JS)
        ->assertNoJavaScriptErrors();
});

it('lets an admin given "see" and "describe" describe a private file through the page, and offers no Delete', function () {
    $paper = 'paper-'.Str::random(8).'.pdf';
    $paperId = libraryScreenPaperNamed($paper);
    $email = (string) DB::table('access.staff_users')
        ->where('id', Fx::staffWith([PlatformPermissions::MEDIA_UPDATE, PlatformPermissions::MEDIA_PRIVATE_VIEW], ['sa'], RoleLevel::Admin))
        ->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', LIBRARY_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->assertPathIs('/admin')
        ->navigate('/admin/media');

    // An id begins with a digit, which a "#id" selector cannot: hence the attribute form.
    $page->assertSee($paper)
        ->assertNotPresent("[data-test=\"delete-{$paperId}\"]")
        ->click("[data-test=\"describe-{$paperId}\"]")
        ->type("[id=\"{$paperId}-alt_en\"]", 'The company paper')
        ->press('Save Description');

    // The save is a request the page sends in the background, served by this same process: give
    // it its moment rather than read the row before it arrives.
    for ($tries = 0; $tries < 25 && DB::table('platform.media')->where('id', $paperId)->value('alt_en') === null; $tries++) {
        $page->wait(0.2);
    }

    expect(DB::table('platform.media')->where('id', $paperId)->value('alt_en'))->toBe('The company paper');

    $page->assertNoJavaScriptErrors();
});

it('shows a private file in the grid as its name, date and use, without a picture', function () {
    $paper = 'paper-'.Str::random(8).'.pdf';
    $paperId = libraryScreenPaperNamed($paper);
    $date = libraryScreenShownDate($paperId);

    $page = visit('/admin/sign-in')
        ->type('#email', libraryScreenEmail())
        ->type('#password', LIBRARY_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->assertPathIs('/admin')
        ->navigate('/admin/media');

    $page->click('[data-test="view-switch"]')
        ->assertSee($paper)
        ->assertScript(<<<JS
            (() => {
                const tile = [...document.querySelectorAll('li')].find((li) => li.textContent.includes('{$paper}'));
                return tile !== undefined
                    && (tile.querySelector('time')?.getAttribute('datetime') ?? '').startsWith('{$date}')
                    && tile.textContent.includes('Not used')
                    && ! tile.textContent.includes('2.3 MB')
                    // Neither a picture nor the placeholder drawn where a picture is missing.
                    && ! tile.textContent.includes('Ready')
                    && ! tile.textContent.includes('application/pdf')
                    && tile.querySelector('img') === null;
            })()
            JS)
        ->assertNoJavaScriptErrors();
});

it('shows a reader who may not see private files an entry about one, without which file it is (amendment 8(c))', function () {
    $paperId = libraryScreenPaperNamed('paper-'.Str::random(8).'.pdf');
    // Now, and filtered to its action below, so it is on the first page however much this database
    // holds from earlier runs.
    $entryId = (string) DB::table('platform.audit_entries')->insertGetId([
        'occurred_at' => now()->toDateTimeString(),
        'recorded_at' => now()->toDateTimeString(),
        'source' => 'WEB',
        'store_id' => null,
        'actor_type' => 'SYSTEM',
        'action' => 'platform.media.uploaded',
        'subject_type' => 'platform.media',
        'subject_id' => $paperId,
        'changes' => json_encode(['visibility' => [null, 'PRIVATE'], 'for_module' => [null, 'b2b']], JSON_THROW_ON_ERROR),
    ]);
    $email = (string) DB::table('access.staff_users')
        ->where('id', Fx::staffWith([PlatformPermissions::AUDIT_VIEW], ['*'], RoleLevel::Admin))
        ->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', LIBRARY_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->assertPathIs('/admin')
        ->navigate('/admin/audit?action=platform.media.uploaded');

    $page->assertPresent("[data-test=\"withheld-{$entryId}\"]")
        ->assertSee('a private file')
        ->assertDontSee($paperId)
        ->assertDontSee('b2b')
        ->assertNoJavaScriptErrors();
});

it('does not offer "private" when uploading to someone who may not see private files', function () {
    $uploader = (string) DB::table('access.staff_users')
        ->where('id', Fx::staffWith([PlatformPermissions::MEDIA_UPLOAD], ['sa']))
        ->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $uploader)
        ->type('#password', LIBRARY_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->assertPathIs('/admin')
        ->navigate('/admin/media');

    // The form is there, with one choice fewer.
    $page->assertPresent('[data-test="file"]')
        ->assertPresent('option[value="PUBLIC"]')
        ->assertNotPresent('option[value="PRIVATE"]')
        ->assertNoJavaScriptErrors();
});

it('offers "private" when uploading to a Super Admin', function () {
    $page = visit('/admin/sign-in')
        ->type('#email', libraryScreenEmail())
        ->type('#password', LIBRARY_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->assertPathIs('/admin')
        ->navigate('/admin/media');

    $page->assertPresent('option[value="PUBLIC"]')
        ->assertPresent('option[value="PRIVATE"]')
        ->assertNoJavaScriptErrors();
});

it('draws the audit log with its filters', function () {
    $page = visit('/admin/sign-in')
        ->type('#email', libraryScreenEmail())
        ->type('#password', LIBRARY_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->assertPathIs('/admin')
        ->navigate('/admin/audit?action=platform.currency.created');

    // The seeder added three currencies, and every one was audited. Filtered to that action, they
    // stay on the first page however many sign-ins earlier runs have left in this database.
    $page->assertSee('Audit Log')
        ->assertSee('Currency added')
        ->assertSee('Filters')
        ->assertNoJavaScriptErrors();

    // Filtering keeps the person on the log rather than sending them anywhere.
    $page->click('[data-test="apply"]')
        ->assertPathIs('/admin/audit')
        ->assertNoJavaScriptErrors();
});
