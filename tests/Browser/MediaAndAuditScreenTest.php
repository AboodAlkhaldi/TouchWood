<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    $page->assertSee('Media library')
        ->assertSee($filename)
        // No thumbnail for an image still being processed: Platform has nothing to give yet.
        ->assertSee('Being processed')
        ->assertNoJavaScriptErrors();

    $page->click('[data-test="view-switch"]')
        ->assertSee($filename)
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
        ->navigate('/admin/audit');

    // The seeder opened three stores and added three currencies, and every one was audited: this
    // log is never empty.
    $page->assertSee('Audit log')
        ->assertSee('Currency added')
        ->assertSee('Filters')
        ->assertNoJavaScriptErrors();

    // Filtering keeps the person on the log rather than sending them anywhere.
    $page->click('[data-test="apply"]')
        ->assertPathIs('/admin/audit')
        ->assertNoJavaScriptErrors();
});
