<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Application\Query\RoleEditorPermissions\EditorPermission;
use Modules\Access\Application\Query\RoleEditorPermissions\RoleEditorPermissions;
use Modules\Access\Application\Query\RoleEditorPermissions\RoleEditorPermissionsHandler;
use Modules\Access\Domain\Exception\AdminOnlyPermission;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMedia;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMediaHandler;
use Modules\Platform\Application\Command\RetryMediaVariants\RetryMediaVariants;
use Modules\Platform\Application\Command\RetryMediaVariants\RetryMediaVariantsHandler;
use Modules\Platform\Application\Command\UpdateMediaAltText\UpdateMediaAltText;
use Modules\Platform\Application\Command\UpdateMediaAltText\UpdateMediaAltTextHandler;
use Modules\Platform\Application\Command\UploadMedia\UploadMedia;
use Modules\Platform\Application\Command\UploadMedia\UploadMediaHandler;
use Modules\Platform\Application\Query\ListMedia\ListMedia;
use Modules\Platform\Application\Query\ListMedia\ListMediaHandler;
use Modules\Platform\Application\Query\ListMedia\MediaLibraryPage;
use Modules\Platform\Application\Query\ListMedia\MediaRow;
use Modules\Platform\Domain\Exception\MediaNotFound;
use Modules\Platform\Presentation\Http\Resource\MediaFileRow;
use Modules\Platform\Presentation\Http\Resource\MediaPages;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\ModuleUploadDto;
use Modules\Platform\Public\Enums\MediaVariantsStatus;
use Modules\Platform\Public\Enums\MediaVisibility;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local', ['serve' => true]);
    Queue::fake();
    seed(PlatformSeeder::class);
});

afterEach(function () {
    File::deleteDirectory(privateMediaDirectory());
});

/*
| B2B step 3, amendments 5, 6 and 8 (platform.md §9.4). Private files - a company's papers - are
| listed in the media library only to holders of the admin-only platform.media.private.view; to
| anyone else they do not exist there at all; a holder describes or deletes one only with the usual
| permission on top; and "private" may be chosen when uploading there only by a holder.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

function privateMediaDirectory(): string
{
    return sys_get_temp_dir().'/tw-private-media-tests';
}

/**
 * A file in the library, written straight in: what is being tested is who is shown it.
 */
function privateMediaRow(MediaVisibility $visibility, string $createdAt = '2026-09-20 10:00:00+00', ?string $variantsStatus = null): string
{
    $id = strtolower((string) Str::ulid());
    $private = $visibility === MediaVisibility::Private;

    DB::table('platform.media')->insert([
        'id' => $id,
        'visibility' => $visibility->value,
        'disk' => $private ? 'local' : 'public',
        'object_key' => 'media/'.$id.($private ? '.pdf' : '.jpg'),
        'original_filename' => $private ? 'paper.pdf' : 'photo.jpg',
        'mime' => $private ? 'application/pdf' : 'image/jpeg',
        'bytes' => 2_400_000,
        'checksum' => hash('sha256', $id),
        'variants_status' => $variantsStatus,
        'variants_queued_at' => $variantsStatus === null ? null : $createdAt,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    return $id;
}

function privateMediaPage(?string $cursorCreatedAt = null, ?string $cursorId = null, int $perPage = 24): MediaLibraryPage
{
    return app(ListMediaHandler::class)->handle(new ListMedia($cursorCreatedAt, $cursorId, $perPage));
}

/**
 * @return list<string>
 */
function privateMediaListed(?MediaLibraryPage $page = null): array
{
    return array_map(static fn (MediaRow $row): string => $row->id, ($page ?? privateMediaPage())->media);
}

function privateMediaPdf(): string
{
    File::ensureDirectoryExists(privateMediaDirectory());
    $path = privateMediaDirectory().'/'.uniqid().'-paper.pdf';
    file_put_contents($path, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%".uniqid()."\n%%EOF\n");

    return $path;
}

function privateMediaImage(): string
{
    File::ensureDirectoryExists(privateMediaDirectory());
    $image = imagecreatetruecolor(40, 40);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, random_int(0, 255), 80, 40));
    $path = privateMediaDirectory().'/'.uniqid().'-picture.jpg';
    imagejpeg($image, $path);
    imagedestroy($image);

    return $path;
}

/**
 * An upload through the media library: the staff path, with no module.
 */
function privateMediaUpload(MediaVisibility $visibility): string
{
    return app(UploadMediaHandler::class)->handle(new UploadMedia(
        $visibility,
        $visibility === MediaVisibility::Private ? privateMediaPdf() : privateMediaImage(),
        $visibility === MediaVisibility::Private ? 'paper.pdf' : 'picture.jpg',
    ));
}

describe('the private-files permission', function () {
    it('is a store-free role action in the media group, and admin-only', function () {
        $definition = app(InMemoryPermissionCatalog::class)->definition(PlatformPermissions::MEDIA_PRIVATE_VIEW);

        expect($definition?->audience)->toBe(PermissionAudience::Role)
            ->and($definition?->kind)->toBe(PermissionKind::Global)
            ->and($definition?->reserved)->toBeFalse()
            ->and($definition?->group)->toBe(PermissionGroup::Media)
            ->and(AccessPermissions::adminOnly())->toContain(PlatformPermissions::MEDIA_PRIVATE_VIEW);
    });

    it('goes into an admin role and never into a staff role', function () {
        expect(fn () => Fx::role([PlatformPermissions::MEDIA_PRIVATE_VIEW], RoleLevel::Staff))->toThrow(AdminOnlyPermission::class);

        $roleId = Fx::role([PlatformPermissions::MEDIA_PRIVATE_VIEW], RoleLevel::Admin);

        expect(Fx::rolePermissions($roleId))->toBe([PlatformPermissions::MEDIA_PRIVATE_VIEW]);
    });

    it('is offered to a Super Admin for an admin role, marked admin-only, and not for a staff role', function () {
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $offered = function (RoleLevel $level): ?EditorPermission {
            foreach (app(RoleEditorPermissionsHandler::class)->handle(new RoleEditorPermissions($level)) as $item) {
                if ($item->name === PlatformPermissions::MEDIA_PRIVATE_VIEW) {
                    return $item;
                }
            }

            return null;
        };

        expect($offered(RoleLevel::Admin)?->adminOnly)->toBeTrue()
            ->and($offered(RoleLevel::Staff))->toBeNull();
    });
});

describe('private files in the media library', function () {
    it('lists no private file to staff who may work with media but not see private files', function () {
        $public = privateMediaRow(MediaVisibility::Public, '2026-09-20 09:00:00+00');
        $private = privateMediaRow(MediaVisibility::Private, '2026-09-20 10:00:00+00');
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::MEDIA_UPLOAD, PlatformPermissions::MEDIA_UPDATE, PlatformPermissions::MEDIA_DELETE], ['sa']));

        expect(privateMediaListed())->toBe([$public])
            ->and(privateMediaListed())->not->toContain($private);
    });

    it('lists private files to a Super Admin, and to an admin given the permission', function (Closure $reader) {
        $public = privateMediaRow(MediaVisibility::Public, '2026-09-20 09:00:00+00');
        $private = privateMediaRow(MediaVisibility::Private, '2026-09-20 10:00:00+00');
        Fx::actAsStaff($reader());

        expect(privateMediaListed())->toBe([$private, $public]);
    })->with([
        'a Super Admin, with no role' => [fn () => Fx::staff(superAdmin: true)],
        'an admin holding it' => [fn () => Fx::staffWith([PlatformPermissions::MEDIA_UPLOAD, PlatformPermissions::MEDIA_PRIVATE_VIEW], ['sa'], RoleLevel::Admin)],
    ]);

    it('lists no private file to a staff role that holds the permission anyway, written in behind the rules', function () {
        // Only the authorizer's own admin-only rule can refuse here: the role is a staff role, so
        // the role editor and the role rules would never have let this in.
        $private = privateMediaRow(MediaVisibility::Private, '2026-09-20 10:00:00+00');
        $staffId = Fx::staffWith([PlatformPermissions::MEDIA_UPLOAD], ['sa']);
        DB::table('access.role_permissions')->insert(['role_id' => Fx::roleOf($staffId), 'permission' => PlatformPermissions::MEDIA_PRIVATE_VIEW]);
        app(GrantsReader::class)->refresh($staffId);
        Fx::actAsStaff($staffId);

        expect(Fx::rolePermissions(Fx::roleOf($staffId)))->toContain(PlatformPermissions::MEDIA_PRIVATE_VIEW)
            ->and(privateMediaListed())->not->toContain($private);
    });

    it('keeps every page full for a reader who may not see private files', function () {
        // Newest first: P1 (private), A, P2 (private), B, C.
        privateMediaRow(MediaVisibility::Private, '2026-09-20 15:00:00+00');
        $a = privateMediaRow(MediaVisibility::Public, '2026-09-20 14:00:00+00');
        privateMediaRow(MediaVisibility::Private, '2026-09-20 13:00:00+00');
        $b = privateMediaRow(MediaVisibility::Public, '2026-09-20 12:00:00+00');
        $c = privateMediaRow(MediaVisibility::Public, '2026-09-20 11:00:00+00');
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::MEDIA_UPLOAD], ['sa']));

        $first = privateMediaPage(perPage: 2);
        $second = privateMediaPage($first->nextCreatedAt, $first->nextId, 2);

        expect(privateMediaListed($first))->toBe([$a, $b])
            ->and($first->nextId)->toBe($b)
            ->and(privateMediaListed($second))->toBe([$c])
            ->and($second->nextCreatedAt)->toBeNull()
            ->and($second->nextId)->toBeNull();
    });

    it('offers no next page when all that is left is private', function () {
        $a = privateMediaRow(MediaVisibility::Public, '2026-09-20 14:00:00+00');
        $b = privateMediaRow(MediaVisibility::Public, '2026-09-20 13:00:00+00');
        privateMediaRow(MediaVisibility::Private, '2026-09-20 12:00:00+00');
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::MEDIA_UPLOAD], ['sa']));

        $page = privateMediaPage(perPage: 2);

        // A "more" that led to nothing would still tell them something is there.
        expect(privateMediaListed($page))->toBe([$a, $b])
            ->and($page->nextCreatedAt)->toBeNull()
            ->and($page->nextId)->toBeNull();
    });

    it('gives no link to a private file, even one marked as having its sizes', function () {
        $private = privateMediaRow(MediaVisibility::Private, '2026-09-20 10:00:00+00', 'READY');
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $rows = app(MediaPages::class)->list(app(ListMediaHandler::class), null, null)->media;
        $row = array_values(array_filter($rows, static fn (MediaFileRow $row): bool => $row->id === $private))[0] ?? null;
        // The library's own answer still says it has its sizes: only the private rule keeps the
        // link, and the status with it, off the screen (amendment 6(b)).
        $answered = array_values(array_filter(privateMediaPage()->media, static fn (MediaRow $row): bool => $row->id === $private))[0] ?? null;

        expect($answered?->variantsStatus)->toBe(MediaVariantsStatus::Ready)
            ->and($row?->visibility)->toBe('PRIVATE')
            ->and($row?->variantsStatus)->toBeNull()
            ->and($row?->thumbnailUrl)->toBeNull();
    });

    it('still refuses the library to an admin who holds only the private-files permission', function () {
        privateMediaRow(MediaVisibility::Private);
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::MEDIA_PRIVATE_VIEW], ['sa'], RoleLevel::Admin));

        expect(fn () => privateMediaPage())->toThrow(Unauthorized::class);
    });
});

describe('choosing "private" when uploading in the library', function () {
    it('refuses an uploader who may not see private files, before anything is stored', function () {
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::MEDIA_UPLOAD], ['sa']));
        $before = DB::table('platform.media')->count();

        expect(fn () => privateMediaUpload(MediaVisibility::Private))->toThrow(Unauthorized::class, PlatformPermissions::MEDIA_PRIVATE_VIEW)
            ->and(Storage::disk('local')->allFiles())->toBe([])
            ->and(DB::table('platform.media')->count())->toBe($before);
    });

    it('still takes that uploader\'s public files', function () {
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::MEDIA_UPLOAD], ['sa']));

        $mediaId = privateMediaUpload(MediaVisibility::Public);

        expect(DB::table('platform.media')->where('id', $mediaId)->value('visibility'))->toBe('PUBLIC');
    });

    it('takes a private file from an admin holding the permission, and from a Super Admin', function (Closure $uploader) {
        Fx::actAsStaff($uploader());

        $mediaId = privateMediaUpload(MediaVisibility::Private);

        expect(DB::table('platform.media')->where('id', $mediaId)->value('visibility'))->toBe('PRIVATE');
    })->with([
        'an admin holding it' => [fn () => Fx::staffWith([PlatformPermissions::MEDIA_UPLOAD, PlatformPermissions::MEDIA_PRIVATE_VIEW], ['sa'], RoleLevel::Admin)],
        'a Super Admin' => [fn () => Fx::staff(superAdmin: true)],
    ]);

    it('asks nothing more of a module uploading a private file for its own use', function () {
        Fx::actAsCustomer(Fx::customer());

        $mediaId = app(PlatformApi::class)->uploadMediaFor(new ModuleUploadDto(
            'access', AccessPermissions::ACCOUNT_UPDATE, PermissionScope::global(),
            MediaVisibility::Private, privateMediaPdf(), 'paper.pdf',
        ));

        expect(DB::table('platform.media')->where('id', $mediaId)->value('visibility'))->toBe('PRIVATE');
    });

    it('offers "private" only to someone who may upload and may see private files', function (Closure $reader, bool $offered) {
        Fx::actAsStaff($reader());

        expect(privateMediaPage()->mayUploadPrivate)->toBe($offered);
    })->with([
        'an uploader' => [fn () => Fx::staffWith([PlatformPermissions::MEDIA_UPLOAD], ['sa']), false],
        'an admin who may upload and see them' => [fn () => Fx::staffWith([PlatformPermissions::MEDIA_UPLOAD, PlatformPermissions::MEDIA_PRIVATE_VIEW], ['sa'], RoleLevel::Admin), true],
        'an admin who may see them and delete, but not upload' => [fn () => Fx::staffWith([PlatformPermissions::MEDIA_DELETE, PlatformPermissions::MEDIA_PRIVATE_VIEW], ['sa'], RoleLevel::Admin), false],
    ]);
});

/**
 * What an action threw, or null: the test compares two answers, so it needs the errors themselves.
 */
function privateMediaError(Closure $act): ?Throwable
{
    try {
        $act();
    } catch (Throwable $error) {
        return $error;
    }

    return null;
}

/**
 * The three things the library does to one file by its id, on the staff path.
 *
 * @return array<string, array{0: string, 1: Closure(string): void}> the permission each needs, and the action
 */
function privateMediaActions(): array
{
    return [
        'describing' => [PlatformPermissions::MEDIA_UPDATE, static function (string $id): void {
            app(UpdateMediaAltTextHandler::class)->handle(new UpdateMediaAltText($id, 'ورقة الشركة', 'The company paper'));
        }],
        'retrying its sizes' => [PlatformPermissions::MEDIA_UPLOAD, static function (string $id): void {
            app(RetryMediaVariantsHandler::class)->handle(new RetryMediaVariants($id));
        }],
        'deleting' => [PlatformPermissions::MEDIA_DELETE, static function (string $id): void {
            app(DeleteMediaHandler::class)->handle(new DeleteMedia($id));
        }],
    ];
}

describe('acting on a private file in the library (amendment 8(a))', function () {
    it('answers someone who may not see private files exactly as for an id that never existed, changing nothing', function (RoleLevel $level, string $permission, Closure $act) {
        // FAILED, so that without the rule a retry would go through and queue the file again.
        $private = privateMediaRow(MediaVisibility::Private, variantsStatus: 'FAILED');
        Fx::actAsStaff(Fx::staffWith([$permission], ['sa'], $level));
        $row = DB::table('platform.media')->where('id', $private)->first();
        $entries = DB::table('platform.audit_entries')->count();

        $error = privateMediaError(fn () => $act($private));
        $never = privateMediaError(fn () => $act(strtolower((string) Str::ulid())));

        expect($error)->toBeInstanceOf(MediaNotFound::class)
            ->and($never)->toBeInstanceOf(MediaNotFound::class)
            ->and($error?->getMessage())->toBe(str_replace($never instanceof MediaNotFound ? $never->mediaId : '', $private, (string) $never?->getMessage()))
            ->and(DB::table('platform.media')->where('id', $private)->first())->toEqual($row)
            ->and(DB::table('platform.audit_entries')->count())->toBe($entries);
        Queue::assertNothingPushed();
    })->with([
        'a staff member' => [RoleLevel::Staff],
        'an admin not given it' => [RoleLevel::Admin],
    ])->with(privateMediaActions());

    it('still does it on a public file for the same person', function () {
        $public = privateMediaRow(MediaVisibility::Public);
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::MEDIA_UPDATE, PlatformPermissions::MEDIA_DELETE], ['sa']));

        app(UpdateMediaAltTextHandler::class)->handle(new UpdateMediaAltText($public, 'صورة', 'A picture'));

        expect(DB::table('platform.media')->where('id', $public)->value('alt_en'))->toBe('A picture');

        app(DeleteMediaHandler::class)->handle(new DeleteMedia($public));

        expect(DB::table('platform.media')->where('id', $public)->exists())->toBeFalse();
    });

    it('lets an admin who sees private files describe and delete one only as far as they were given', function (Closure $admin, bool $describes, bool $deletes) {
        $described = privateMediaRow(MediaVisibility::Private);
        $deleted = privateMediaRow(MediaVisibility::Private, '2026-09-20 11:00:00+00');
        Fx::actAsStaff($admin());

        $outcome = static fn (?Throwable $error): string => $error === null ? 'done' : $error::class;

        $describing = privateMediaError(fn () => app(UpdateMediaAltTextHandler::class)->handle(new UpdateMediaAltText($described, 'ورقة الشركة', 'The company paper')));
        $deleting = privateMediaError(fn () => app(DeleteMediaHandler::class)->handle(new DeleteMedia($deleted)));

        // Refused by the name of the permission they lack, like anyone else: they may see the file.
        expect($outcome($describing))->toBe($describes ? 'done' : Unauthorized::class)
            ->and(DB::table('platform.media')->where('id', $described)->value('alt_en'))->toBe($describes ? 'The company paper' : null)
            ->and($outcome($deleting))->toBe($deletes ? 'done' : Unauthorized::class)
            ->and(DB::table('platform.media')->where('id', $deleted)->exists())->toBe(! $deletes);
    })->with([
        'see only' => [fn () => Fx::staffWith([PlatformPermissions::MEDIA_PRIVATE_VIEW, PlatformPermissions::MEDIA_UPLOAD], ['sa'], RoleLevel::Admin), false, false],
        'see and describe' => [fn () => Fx::staffWith([PlatformPermissions::MEDIA_PRIVATE_VIEW, PlatformPermissions::MEDIA_UPDATE], ['sa'], RoleLevel::Admin), true, false],
        'see, describe and delete' => [fn () => Fx::staffWith([PlatformPermissions::MEDIA_PRIVATE_VIEW, PlatformPermissions::MEDIA_UPDATE, PlatformPermissions::MEDIA_DELETE], ['sa'], RoleLevel::Admin), true, true],
    ]);

    it('lets a Super Admin describe, retry and delete a private file', function () {
        $private = privateMediaRow(MediaVisibility::Private, variantsStatus: 'FAILED');
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        app(UpdateMediaAltTextHandler::class)->handle(new UpdateMediaAltText($private, 'ورقة الشركة', 'The company paper'));
        app(RetryMediaVariantsHandler::class)->handle(new RetryMediaVariants($private));

        expect(DB::table('platform.media')->where('id', $private)->value('alt_en'))->toBe('The company paper')
            ->and(DB::table('platform.media')->where('id', $private)->value('variants_status'))->toBe('PENDING');

        app(DeleteMediaHandler::class)->handle(new DeleteMedia($private));

        expect(DB::table('platform.media')->where('id', $private)->exists())->toBeFalse();
    });
});
