<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

    it('deletes a file, and detaches it from whatever was using it', function () {
        $mediaId = libraryScreenFile();
        $staffId = Fx::staff(superAdmin: true);
        DB::table('access.staff_users')->where('id', $staffId)->update(['avatar_media_id' => $mediaId]);

        $browser = libraryScreenSignIn($staffId);
        $browser->post("/admin/media/{$mediaId}/delete")->assertRedirect();

        // Gone, and the staff member who wore it no longer points at it: an avatar does not block
        // a delete, so the use is detached rather than refusing (platform.md 1.4).
        expect(DB::table('platform.media')->where('id', $mediaId)->exists())->toBeFalse()
            ->and(DB::table('access.staff_users')->where('id', $staffId)->value('avatar_media_id'))->toBeNull();
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

/**
 * A company's paper in the library, marked as having sizes so that only the private rule can keep
 * a link off the screen.
 */
function libraryScreenPrivateFile(): string
{
    $id = strtolower((string) Str::ulid());

    DB::table('platform.media')->insert([
        'id' => $id,
        'visibility' => 'PRIVATE',
        'disk' => 'local',
        'object_key' => 'media/'.$id.'.pdf',
        'original_filename' => 'paper.pdf',
        'mime' => 'application/pdf',
        'bytes' => 2_400_000,
        'checksum' => hash('sha256', $id),
        'variants_status' => 'READY',
        'variants_queued_at' => '2026-09-20 11:00:00+00',
        'created_at' => '2026-09-20 11:00:00+00',
        'updated_at' => '2026-09-20 11:00:00+00',
    ]);

    return $id;
}

/**
 * @return list<string> the ids of the files the media library page is handed
 */
function libraryScreenPrivateListed(AdminBrowser $browser): array
{
    $ids = [];

    $browser->get('/admin/media')->assertOk()->assertInertia(function (AssertableInertia $inertia) use (&$ids) {
        /** @var list<array{id: string}> $media */
        $media = $inertia->toArray()['props']['media'];
        $ids = array_column($media, 'id');
    });

    return $ids;
}

describe('private files in the media library screen', function () {
    it('leaves private files off the page for staff, and lists them, with no link, to a Super Admin', function () {
        Storage::fake('local', ['serve' => true]);
        $public = libraryScreenFile();
        $private = libraryScreenPrivateFile();

        $staff = libraryScreenSignIn(Fx::staffWith([PlatformPermissions::MEDIA_UPLOAD, PlatformPermissions::MEDIA_UPDATE, PlatformPermissions::MEDIA_DELETE], ['sa']));

        expect(libraryScreenPrivateListed($staff))->toBe([$public]);

        libraryScreenSignIn(Fx::staff(superAdmin: true))->get('/admin/media')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia
                ->has('media', 2)
                ->where('media.0.id', $private)
                ->where('media.0.visibility', 'PRIVATE')
                ->where('media.0.thumbnailUrl', null)
                ->where('media.1.id', $public)
            );
    });

    it('hands the page a private file\'s name, date and where it is used, what describing and deleting it need, and nothing about the file (amendments 6(b), 8(a))', function () {
        Storage::fake('local', ['serve' => true]);
        $public = libraryScreenFile();
        $private = libraryScreenPrivateFile();
        // Everything a row could otherwise carry: a description, dimensions, a retry.
        DB::table('platform.media')->where('id', $private)->update([
            'alt_ar' => 'ورقة الشركة',
            'alt_en' => 'The company paper',
            'width' => 800,
            'height' => 600,
            'variants_status' => 'FAILED',
        ]);

        /** @var array<string, array<string, mixed>> $rows */
        $rows = [];

        libraryScreenSignIn(Fx::staff(superAdmin: true))->get('/admin/media')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $inertia) use (&$rows) {
                /** @var list<array<string, mixed>> $media */
                $media = $inertia->toArray()['props']['media'];
                $rows = array_column($media, null, 'id');
            });

        $sent = static fn (array $row): array => array_keys(array_filter($row, static fn (mixed $value): bool => $value !== null));

        // The id and the visibility are how the page tells rows apart and draws this one short; the
        // two descriptions fill the Describe form, and deleteBlocked says whether Delete is offered.
        expect($sent($rows[$private]))->toBe(['id', 'filename', 'visibility', 'uploadedAt', 'altAr', 'altEn', 'usedIn', 'deleteBlocked'])
            ->and($rows[$private]['filename'])->toBe('paper.pdf')
            ->and($rows[$private]['altEn'])->toBe('The company paper')
            ->and($rows[$private]['deleteBlocked'])->toBeFalse()
            // The public file beside it keeps everything, so the nulls are the private rule's.
            ->and($rows[$public]['mime'])->toBe('image/jpeg')
            ->and($rows[$public]['bytes'])->toBe(2_400_000)
            ->and($rows[$public]['size'])->toBe('2.3 MB')
            ->and($rows[$public]['variantsStatus'])->toBe('READY')
            ->and($rows[$public]['retryable'])->toBeFalse()
            ->and($rows[$public]['deleteBlocked'])->toBeFalse();
    });

    it('answers a request about a private file, from someone who may not see private files, exactly as for an id that never existed', function (string $permission, string $action) {
        Storage::fake('local', ['serve' => true]);
        Queue::fake();
        $private = libraryScreenPrivateFile();
        DB::table('platform.media')->where('id', $private)->update(['variants_status' => 'FAILED']);
        $row = DB::table('platform.media')->where('id', $private)->first();
        $browser = libraryScreenSignIn(Fx::staffWith([$permission], ['sa']));
        $form = ['alt_ar' => 'ورقة الشركة', 'alt_en' => 'The company paper'];

        $aboutPrivate = AdminBrowser::formError($browser->post("/admin/media/{$private}/{$action}", $form)->assertRedirect());
        $aboutNothing = AdminBrowser::formError($browser->post('/admin/media/'.strtolower((string) Str::ulid())."/{$action}", $form)->assertRedirect());

        expect($aboutPrivate)->toBe(trans('platform::errors.media_not_found.detail', [], 'en'))
            ->and($aboutPrivate)->toBe($aboutNothing)
            ->and(DB::table('platform.media')->where('id', $private)->first())->toEqual($row);
        Queue::assertNothingPushed();
    })->with([
        'describing' => [PlatformPermissions::MEDIA_UPDATE, 'alt'],
        'retrying' => [PlatformPermissions::MEDIA_UPLOAD, 'retry'],
        'deleting' => [PlatformPermissions::MEDIA_DELETE, 'delete'],
    ]);

    it('offers "private" when uploading only to someone who may see private files', function () {
        libraryScreenSignIn(Fx::staffWith([PlatformPermissions::MEDIA_UPLOAD], ['sa']))->get('/admin/media')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia->where('mayUpload', true)->where('mayUploadPrivate', false));

        libraryScreenSignIn(Fx::staff(superAdmin: true))->get('/admin/media')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia->where('mayUpload', true)->where('mayUploadPrivate', true));
    });

    it('sends a private upload back with the refusal when the uploader may not see private files', function () {
        Storage::fake('public');
        Storage::fake('local', ['serve' => true]);
        Queue::fake();
        $before = DB::table('platform.media')->count();
        $browser = libraryScreenSignIn(Fx::staffWith([PlatformPermissions::MEDIA_UPLOAD], ['sa']));

        $response = $browser->post('/admin/media', [
            'file' => UploadedFile::fake()->create('paper.pdf', 10, 'application/pdf'),
            'visibility' => 'PRIVATE',
        ])->assertRedirect();

        // In the form, in their language (their account is in English), rather than a 403 page.
        expect(AdminBrowser::formError($response))->toBe(trans('errors.unauthorized.detail', [], 'en'))
            ->and(Storage::disk('local')->allFiles())->toBe([])
            ->and(DB::table('platform.media')->count())->toBe($before);
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

    it('sends a reader who may not see private files an entry about one without which file or what changed, even once it is deleted (amendment 8(c))', function () {
        Storage::fake('public');
        Storage::fake('local', ['serve' => true]);
        Queue::fake();
        $adminId = Fx::staff(superAdmin: true);
        $superAdmin = libraryScreenSignIn($adminId);

        $superAdmin->post('/admin/media', [
            'file' => UploadedFile::fake()->createWithContent('paper.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n"),
            'visibility' => 'PRIVATE',
        ])->assertRedirect();
        $mediaId = (string) DB::table('platform.media')->where('visibility', 'PRIVATE')->value('id');
        // Gone afterwards, so only what its upload recorded can still say it was private.
        $superAdmin->post("/admin/media/{$mediaId}/delete")->assertRedirect();

        expect($mediaId)->not->toBe('')
            ->and(DB::table('platform.media')->where('id', $mediaId)->exists())->toBeFalse();

        libraryScreenSignIn(Fx::staffWith([PlatformPermissions::AUDIT_VIEW], ['*'], RoleLevel::Admin))
            ->get('/admin/audit?action=platform.media.uploaded')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia
                ->has('entries', 1)
                ->where('entries.0.withheld', true)
                ->where('entries.0.subjectId', null)
                ->where('entries.0.changes', [])
                // What was done, and by whom, still reach them — the Super Admin who uploaded it as
                // "System administrator", with no id and no address (access.md amendment 54).
                ->where('entries.0.action', 'platform.media.uploaded')
                ->where('entries.0.actorId', null)
                ->where('entries.0.actorName', 'System administrator')
                ->where('entries.0.ipAddress', null)
            );

        $superAdmin->get('/admin/audit?action=platform.media.uploaded')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia
                ->has('entries', 1)
                ->where('entries.0.withheld', false)
                ->where('entries.0.subjectId', $mediaId)
                // Another Super Admin — here, themselves — is named, with the id (amendment 54).
                ->where('entries.0.actorId', $adminId)
            );
    });
});
