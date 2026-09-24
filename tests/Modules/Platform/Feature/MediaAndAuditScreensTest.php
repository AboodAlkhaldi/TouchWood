<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/*
| Stage 2b, step 3 - the media library and the audit log over HTTP (frontend.md §3.5, E5 and E6).
|
| Every helper here is named after this file's subject. A function declared in a Pest file is global
| to the whole suite, so two files sharing a name stop every run (project conventions).
*/

function libraryScreenSignIn(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.12.0.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', [
        'code' => RecordingSecurityMessages::installed()->lastCode(),
    ])->assertRedirect('/admin');

    return $browser;
}

function libraryScreenFile(): string
{
    $id = strtolower((string) Str::ulid());

    DB::table('platform.media')->insert([
        'id' => $id,
        'visibility' => 'PUBLIC',
        'disk' => 'public',
        'object_key' => 'media/'.$id.'.jpg',
        'original_filename' => 'photo.jpg',
        'mime' => 'image/jpeg',
        'bytes' => 2_400_000,
        'checksum' => hash('sha256', $id),
        'variants_status' => 'READY',
        'variants_queued_at' => '2026-09-20 10:00:00+00',
        'created_at' => '2026-09-20 10:00:00+00',
        'updated_at' => '2026-09-20 10:00:00+00',
    ]);

    return $id;
}

describe('the media library screen', function () {
    it('opens for somebody who may work with media, and says what they may do', function () {
        libraryScreenFile();
        $browser = libraryScreenSignIn(Fx::staffWith([PlatformPermissions::MEDIA_UPDATE], ['sa']));

        $browser->get('/admin/media')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia
                ->component('Platform/Admin/Media/Index')
                ->has('media', 1)
                // The size as a person reads it, not a count of bytes.
                ->where('media.0.size', '2.3 MB')
                ->where('mayUpdate', true)
                ->where('mayUpload', false)
                ->where('mayDelete', false)
            );
    });

    it('refuses the library to somebody who may not touch media', function () {
        $browser = libraryScreenSignIn(Fx::staffWith([PlatformPermissions::STORE_VIEW], ['sa']));

        $browser->get('/admin/media')->assertForbidden();
    });

    it('saves a description', function () {
        $mediaId = libraryScreenFile();
        $browser = libraryScreenSignIn(Fx::staffWith([PlatformPermissions::MEDIA_UPDATE], ['sa']));

        $browser->post("/admin/media/{$mediaId}/alt", [
            'alt_ar' => 'صورة المتجر',
            'alt_en' => 'The shopfront',
        ])->assertRedirect();

        expect(DB::table('platform.media')->where('id', $mediaId)->value('alt_en'))->toBe('The shopfront');
    });
});

describe('the audit log screen', function () {
    it('opens for a reader, showing what is in their stores', function () {
        // The seeder opened three stores and added three currencies, and every one of those was
        // audited: the log is never empty in this system.
        $browser = libraryScreenSignIn(Fx::staffWith([PlatformPermissions::AUDIT_VIEW], ['sa'], RoleLevel::Admin));

        $browser->get('/admin/audit')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $inertia) {
                $inertia->component('Platform/Admin/Audit/Index');

                /** @var list<array{action: string, storeName: string|null}> $entries */
                $entries = $inertia->toArray()['props']['entries'];

                // Their store's own entries, and nothing that belongs to no store: a currency is
                // the system's rather than a shop's.
                expect($entries)->not->toBe([])
                    ->and(array_column($entries, 'storeName'))->not->toContain(null);
            });
    });

    it('shows a Super Admin the entries that belong to no store', function () {
        $browser = libraryScreenSignIn(Fx::staff(superAdmin: true));

        $browser->get('/admin/audit?action=platform.currency.created')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia
                ->has('entries', 3)
                ->where('entries.0.action', 'platform.currency.created')
                // In words, from the module that records it.
                ->where('entries.0.actionLabel', 'Currency added')
                ->where('entries.0.storeName', null)
            );
    });

    it('refuses the log to somebody without it', function () {
        $browser = libraryScreenSignIn(Fx::staffWith([PlatformPermissions::STORE_VIEW], ['sa']));

        $browser->get('/admin/audit')->assertForbidden();
    });
});
