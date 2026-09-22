<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Access\Application\Authorization\InvalidPermissionCheck;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\ModuleUploadDto;
use Modules\Platform\Public\Enums\MediaVisibility;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;
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
    File::deleteDirectory(moduleUploadDirectory());
});

/**
 * Local helpers: a Pest file's functions are global to the whole suite, so these are named after
 * this file's subject rather than borrowed from another file, which would break a single-file run.
 */
function moduleUploadDirectory(): string
{
    return sys_get_temp_dir().'/tw-module-upload-tests';
}

/**
 * @param  int<0, 255>  $shade  makes two files of the same size differ
 */
function moduleUploadImage(int $shade = 120): string
{
    File::ensureDirectoryExists(moduleUploadDirectory());
    $image = imagecreatetruecolor(40, 40);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, $shade, 80, 40));
    $path = moduleUploadDirectory().'/'.uniqid().'-picture.jpg';
    imagejpeg($image, $path);
    imagedestroy($image);

    return $path;
}

function uploadForModule(string $module, string $permission, ?PermissionScope $scope = null): string
{
    return app(PlatformApi::class)->uploadMediaFor(new ModuleUploadDto(
        $module,
        $permission,
        $scope ?? PermissionScope::global(),
        MediaVisibility::Public,
        moduleUploadImage(),
        'picture.jpg',
    ));
}

/**
 * Stage 2b, P1. A module uploads a file for its own use, and Platform checks that module's own
 * permission for the change instead of platform.media.upload.
 */
describe('a module uploading a file for its own use', function () {
    it('lets a staff member with no media permission at all set their own picture', function () {
        // The case this exists for: access.own_account.update is automatic for every staff member,
        // and platform.media.upload is a media permission almost nobody holds. Before this, the
        // avatar column and its screen existed with no way on earth to fill them.
        $staffId = Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']);
        Fx::actAsStaff($staffId);

        $mediaId = uploadForModule('access', AccessPermissions::OWN_ACCOUNT_UPDATE);

        expect(app(PlatformApi::class)->media($mediaId))->not->toBeNull()
            ->and(DB::table('platform.media')->where('id', $mediaId)->value('uploaded_by'))->toBe($staffId);
    });

    it('refuses a permission that belongs to another module', function () {
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::MEDIA_UPLOAD], ['sa']));

        // Naming someone else's permission, even one the person holds, is refused: a module may
        // only vouch for its own.
        expect(fn () => uploadForModule('access', PlatformPermissions::MEDIA_UPLOAD))
            ->toThrow(DomainError::class, 'does not belong');
    });

    it('refuses a permission no module has declared', function () {
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']));

        // The authorizer refuses it - with InvalidPermissionCheck, a programming error rather than
        // a refusal, because a module naming a permission nobody declared is a bug. What matters
        // here is that it throws and that nothing reaches storage under such a name.
        expect(fn () => uploadForModule('access', 'access.invented.permission'))
            ->toThrow(InvalidPermissionCheck::class, 'no module declares it')
            ->and(DB::table('platform.media')->count())->toBe(0);
    });

    it('refuses someone who does not hold the module permission either', function () {
        // Holding the action is still the point: a customer has no staff account at all.
        Fx::actAsCustomer(Fx::customer());

        expect(fn () => uploadForModule('access', AccessPermissions::OWN_ACCOUNT_UPDATE))->toThrow(Unauthorized::class);
    });

    it('refuses a staff member who does not hold the named permission', function () {
        // The one that matters: a real staff account, a real declared permission of the module,
        // which this person simply does not hold. Naming your own permission is not holding it
        // (review of step 0).
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']));

        expect(fn () => uploadForModule('access', AccessPermissions::STAFF_INVITE, PermissionScope::store(StoreId::fromString(Fx::storeId('sa')))))
            ->toThrow(Unauthorized::class)
            ->and(DB::table('platform.media')->count())->toBe(0);
    });

    it('gives back the same media for the same public image, as any other upload does', function () {
        $staffId = Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']);
        Fx::actAsStaff($staffId);
        $path = moduleUploadImage(200);

        $first = app(PlatformApi::class)->uploadMediaFor(new ModuleUploadDto(
            'access', AccessPermissions::OWN_ACCOUNT_UPDATE, PermissionScope::global(),
            MediaVisibility::Public, $path, 'picture.jpg',
        ));
        $again = app(PlatformApi::class)->uploadMediaFor(new ModuleUploadDto(
            'access', AccessPermissions::OWN_ACCOUNT_UPDATE, PermissionScope::global(),
            MediaVisibility::Public, moduleUploadImage(200), 'picture.jpg',
        ));

        // Nothing about the upload changes because a module asked for it: one row, one file.
        expect($again)->toBe($first)
            ->and(DB::table('platform.media')->count())->toBe(1);
    });

    it('writes the same audit entry as any other upload', function () {
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']));

        $mediaId = uploadForModule('access', AccessPermissions::OWN_ACCOUNT_UPDATE);

        $changes = (string) DB::table('platform.audit_entries')->where('subject_id', $mediaId)
            ->where('action', 'platform.media.uploaded')->value('changes');

        // An upload a module asked for is not the same event as one through the media library, and
        // the log has to say which it was, and under what (review of step 0).
        expect(DB::table('platform.audit_entries')->where('subject_id', $mediaId)->where('action', 'platform.media.uploaded')->count())->toBe(1)
            ->and($changes)->toContain('for_module')
            ->and($changes)->toContain('access')
            ->and($changes)->toContain(AccessPermissions::OWN_ACCOUNT_UPDATE);
    });
});
