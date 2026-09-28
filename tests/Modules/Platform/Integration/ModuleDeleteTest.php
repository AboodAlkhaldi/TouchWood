<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Access\Application\Authorization\InvalidPermissionCheck;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMedia;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMediaHandler;
use Modules\Platform\Domain\Exception\InvalidMediaAttribute;
use Modules\Platform\Domain\Exception\MediaInUse;
use Modules\Platform\Domain\Exception\MediaNotFound;
use Modules\Platform\Public\Contracts\MediaUsage;
use Modules\Platform\Public\Contracts\MediaUsages;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\MediaUseDto;
use Modules\Platform\Public\Dto\ModuleDeleteDto;
use Modules\Platform\Public\Dto\ModuleUploadDto;
use Modules\Platform\Public\Enums\MediaVisibility;
use Modules\Platform\Public\Events\MediaDeleted;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
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
    File::deleteDirectory(moduleDeleteDirectory());
});

/*
| B2B step 3, amendments 4 and 5 (platform.md §9.4). A module deletes a private file it holds for its
| own use - the paper a new upload replaced, the files of a discarded draft - checked against that
| module's own permission instead of platform.media.delete, and refused while any use remains.
|
| Every helper and class here is named after this file's subject: a Pest file's functions and classes
| are global to the whole suite.
*/

function moduleDeleteDirectory(): string
{
    return sys_get_temp_dir().'/tw-module-delete-tests';
}

/**
 * A real PDF on local disk, as a customer's browser would send it.
 */
function moduleDeletePdf(): string
{
    File::ensureDirectoryExists(moduleDeleteDirectory());
    $path = moduleDeleteDirectory().'/'.uniqid().'-paper.pdf';
    file_put_contents($path, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%".uniqid()."\n%%EOF\n");

    return $path;
}

function moduleDeleteImage(): string
{
    File::ensureDirectoryExists(moduleDeleteDirectory());
    $image = imagecreatetruecolor(40, 40);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, random_int(0, 255), 80, 40));
    $path = moduleDeleteDirectory().'/'.uniqid().'-picture.jpg';
    imagejpeg($image, $path);
    imagedestroy($image);

    return $path;
}

/**
 * A file uploaded by the acting person through the module path, as B2B uploads a company's papers.
 */
function moduleDeleteUpload(MediaVisibility $visibility = MediaVisibility::Private): string
{
    return app(PlatformApi::class)->uploadMediaFor(new ModuleUploadDto(
        'access',
        AccessPermissions::ACCOUNT_UPDATE,
        PermissionScope::global(),
        $visibility,
        $visibility === MediaVisibility::Private ? moduleDeletePdf() : moduleDeleteImage(),
        $visibility === MediaVisibility::Private ? 'paper.pdf' : 'picture.jpg',
    ));
}

function moduleDeleteFor(string $mediaId, string $module = 'access', string $permission = AccessPermissions::ACCOUNT_UPDATE, ?PermissionScope $scope = null): void
{
    app(PlatformApi::class)->deleteMediaFor(new ModuleDeleteDto($module, $permission, $scope ?? PermissionScope::global(), $mediaId));
}

function moduleDeleteRow(string $mediaId): bool
{
    return DB::table('platform.media')->where('id', $mediaId)->exists();
}

/**
 * The error the call throws, so a test can read its fields rather than only its message.
 */
function moduleDeleteError(Closure $call): Throwable
{
    try {
        $call();
    } catch (Throwable $error) {
        return $error;
    }

    throw new LogicException('Nothing was thrown.');
}

/**
 * The changes of the one platform.media.deleted entry for this media, with its keys sorted: jsonb
 * does not keep key order, and each [from, to] pair must still be compared in its own order.
 *
 * @return array<string, mixed>
 */
function moduleDeleteChanges(string $mediaId): array
{
    $changes = json_decode((string) DB::table('platform.audit_entries')->where('action', 'platform.media.deleted')
        ->where('subject_id', $mediaId)->value('changes'), true);

    if (! is_array($changes)) {
        throw new LogicException("No deletion entry for {$mediaId}.");
    }

    ksort($changes);

    return $changes;
}

/**
 * A module's use of a file: a "photo" could be detached, a "document" blocks deleting.
 */
final class ModuleDeleteTestUsage implements MediaUsage
{
    /** @var list<string> every media id detach() was asked for */
    public static array $detached = [];

    public function usesOf(string $mediaId): array
    {
        $uses = [];

        foreach (DB::table('testing_module_delete_refs')->where('media_id', $mediaId)->orderBy('id')->get() as $row) {
            $uses[] = new MediaUseDto('testing.paper', (string) $row->id, $row->kind === 'document');
        }

        return $uses;
    }

    public function detach(string $mediaId): void
    {
        self::$detached[] = $mediaId;
        DB::table('testing_module_delete_refs')->where('media_id', $mediaId)->where('kind', 'photo')->delete();
    }
}

/**
 * Records every check and allows it, so a test can see exactly what was asked.
 */
final class ModuleDeleteAuthorizations implements Authorizer
{
    /** @var list<array{string, string}> */
    public array $checks = [];

    public function authorize(string $permission, PermissionScope $scope): void
    {
        $this->checks[] = [$permission, $scope->describe()];
    }

    public function storesWith(string $permission): ?array
    {
        return null;
    }

    public function isUnlimited(): bool
    {
        return false;
    }
}

describe('a module deleting a private file it holds', function () {
    it('lets a customer who holds no media permission delete it, files and all, and announces it once', function () {
        Fx::actAsCustomer(Fx::customer());
        // Before anything is resolved: the handler keeps the dispatcher it was built with.
        Event::fake([MediaDeleted::class]);
        $mediaId = moduleDeleteUpload();
        expect(Storage::disk('local')->allFiles())->toHaveCount(1);

        moduleDeleteFor($mediaId);

        expect(moduleDeleteRow($mediaId))->toBeFalse()
            ->and(Storage::disk('local')->allFiles())->toBe([]);
        Event::assertDispatchedTimes(MediaDeleted::class, 1);
        Event::assertDispatched(MediaDeleted::class, fn (MediaDeleted $event): bool => $event->mediaId === $mediaId);
    });

    it('logs one deletion, by the customer, naming the module and the permission as an upload does', function () {
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);
        $mediaId = moduleDeleteUpload();
        $checksum = (string) DB::table('platform.media')->where('id', $mediaId)->value('checksum');

        moduleDeleteFor($mediaId);

        $entries = DB::table('platform.audit_entries')->where('action', 'platform.media.deleted')->where('subject_id', $mediaId)->get();
        $entry = $entries->first();

        expect($entries)->toHaveCount(1)
            ->and($entry?->actor_type)->toBe('CUSTOMER')
            ->and($entry?->actor_id)->toBe($customerId)
            // From nothing to the value, exactly as MediaAudit::uploaded records a module's upload.
            ->and(moduleDeleteChanges($mediaId))->toBe([
                'checksum' => [$checksum, null],
                'for_module' => [null, 'access'],
                'under_permission' => [null, AccessPermissions::ACCOUNT_UPDATE],
            ]);
    });

    it('writes no module and no permission into a staff deletion', function () {
        Fx::actAsCustomer(Fx::customer());
        $mediaId = moduleDeleteUpload();
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        app(DeleteMediaHandler::class)->handle(new DeleteMedia($mediaId));

        expect(array_keys(moduleDeleteChanges($mediaId)))->toBe(['checksum']);
    });

    it('refuses a public image, changing nothing', function () {
        Fx::actAsCustomer(Fx::customer());
        $mediaId = moduleDeleteUpload(MediaVisibility::Public);
        $files = Storage::disk('local')->allFiles();
        $entries = DB::table('platform.audit_entries')->count();

        $error = moduleDeleteError(fn () => moduleDeleteFor($mediaId));

        expect($error)->toBeInstanceOf(InvalidMediaAttribute::class)
            ->and($error instanceof InvalidMediaAttribute ? $error->attribute : null)->toBe('visibility')
            ->and(moduleDeleteRow($mediaId))->toBeTrue()
            ->and($files)->toHaveCount(1)
            ->and(Storage::disk('local')->allFiles())->toBe($files)
            ->and(DB::table('platform.audit_entries')->count())->toBe($entries);
    });

    it('refuses while another module really uses it, and never detaches that use', function () {
        Fx::actAsCustomer(Fx::customer());
        $mediaId = moduleDeleteUpload();
        // Access's own use, which a staff delete would detach: a staff member's picture.
        $staffId = Fx::staff();
        DB::table('access.staff_users')->where('id', $staffId)->update(['avatar_media_id' => $mediaId]);

        expect(fn () => moduleDeleteFor($mediaId))->toThrow(MediaInUse::class, "access.staff_user {$staffId}")
            ->and(DB::table('access.staff_users')->where('id', $staffId)->value('avatar_media_id'))->toBe($mediaId)
            ->and(moduleDeleteRow($mediaId))->toBeTrue()
            ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    });

    it('reports a file that does not exist, or an id that is not one, to someone allowed to delete', function (string $mediaId) {
        Fx::actAsCustomer(Fx::customer());

        expect(fn () => moduleDeleteFor($mediaId))->toThrow(MediaNotFound::class);
    })->with([
        'an unknown id' => ['01j8z3k4m5n6p7q8r9s0t1v2w3'],
        'not an id' => ['not-an-id'],
    ]);
});

describe('a module deleting a file some module still uses', function () {
    beforeEach(function () {
        ModuleDeleteTestUsage::$detached = [];
        Schema::create('testing_module_delete_refs', function (Blueprint $table) {
            $table->id();
            $table->char('media_id', 26);
            $table->string('kind', 16)->default('photo');
            $table->foreign('media_id')->references('id')->on('platform.media')->restrictOnDelete();
        });
        app(MediaUsages::class)->register('testing', ModuleDeleteTestUsage::class);
    });

    it('refuses even a use that would not block a staff delete, and detaches nothing', function () {
        Fx::actAsCustomer(Fx::customer());
        $mediaId = moduleDeleteUpload();
        $ref = DB::table('testing_module_delete_refs')->insertGetId(['media_id' => $mediaId, 'kind' => 'photo']);

        expect(fn () => moduleDeleteFor($mediaId))->toThrow(MediaInUse::class, "testing.paper {$ref}")
            ->and(ModuleDeleteTestUsage::$detached)->toBe([])
            ->and(DB::table('testing_module_delete_refs')->count())->toBe(1)
            ->and(moduleDeleteRow($mediaId))->toBeTrue()
            ->and(Storage::disk('local')->allFiles())->toHaveCount(1);

        // The contrast: staff deleting the same file detach that use, as they always have.
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        app(DeleteMediaHandler::class)->handle(new DeleteMedia($mediaId));

        expect(ModuleDeleteTestUsage::$detached)->toBe([$mediaId])
            ->and(moduleDeleteRow($mediaId))->toBeFalse();
    });

    it('refuses a blocking use, naming it', function () {
        Fx::actAsCustomer(Fx::customer());
        $mediaId = moduleDeleteUpload();
        $ref = DB::table('testing_module_delete_refs')->insertGetId(['media_id' => $mediaId, 'kind' => 'document']);

        expect(fn () => moduleDeleteFor($mediaId))->toThrow(MediaInUse::class, "testing.paper {$ref}")
            ->and(ModuleDeleteTestUsage::$detached)->toBe([])
            ->and(moduleDeleteRow($mediaId))->toBeTrue()
            ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    });
});

describe('the permission a module names for a delete', function () {
    it('refuses a permission that belongs to another module, even one the person holds', function () {
        Fx::actAsCustomer(Fx::customer());
        $mediaId = moduleDeleteUpload();
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::MEDIA_DELETE], ['sa']));

        expect(fn () => moduleDeleteFor($mediaId, 'access', PlatformPermissions::MEDIA_DELETE))
            ->toThrow(InvalidMediaAttribute::class, 'does not belong')
            ->and(moduleDeleteRow($mediaId))->toBeTrue();
    });

    it('refuses a permission no module has declared', function () {
        Fx::actAsCustomer(Fx::customer());
        $mediaId = moduleDeleteUpload();

        expect(fn () => moduleDeleteFor($mediaId, 'access', 'access.invented.permission'))
            ->toThrow(InvalidPermissionCheck::class)
            ->and(moduleDeleteRow($mediaId))->toBeTrue();
    });

    it('refuses someone who does not hold the permission named, whatever media permission they hold', function () {
        Fx::actAsCustomer(Fx::customer());
        $mediaId = moduleDeleteUpload();
        // A staff member, no Super Admin, holding the staff delete itself.
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::MEDIA_DELETE], ['sa']));

        expect(fn () => moduleDeleteFor($mediaId))->toThrow(Unauthorized::class)
            ->and(moduleDeleteRow($mediaId))->toBeTrue()
            ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    });

    it('checks the permission before looking the file up, so a refusal says nothing about whether it exists', function () {
        $missing = '01j8z3k4m5n6p7q8r9s0t1v2w3';
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::MEDIA_DELETE], ['sa']));

        expect(moduleDeleteError(fn () => moduleDeleteFor($missing, 'access', PlatformPermissions::MEDIA_DELETE)))
            ->toBeInstanceOf(InvalidMediaAttribute::class)
            ->and(moduleDeleteError(fn () => moduleDeleteFor($missing)))->toBeInstanceOf(Unauthorized::class);
    });

    it('checks exactly the permission named, in the scope named, and nothing else', function (Closure $scoped) {
        /** @var array{0: PermissionScope, 1: string} $given */
        $given = $scoped();
        [$scope, $described] = $given;
        Fx::actAsCustomer(Fx::customer());
        $mediaId = moduleDeleteUpload();

        // After acting, which forgets the scoped services: the recorder must be what they are
        // built with.
        app()->forgetScopedInstances();
        $log = new ModuleDeleteAuthorizations;
        app()->instance(Authorizer::class, $log);

        moduleDeleteFor($mediaId, scope: $scope);

        expect($log->checks)->toBe([[AccessPermissions::ACCOUNT_UPDATE, $described]])
            ->and(moduleDeleteRow($mediaId))->toBeFalse();
    })->with([
        'global' => [fn () => [PermissionScope::global(), 'global']],
        // Not global, so a handler that checked globally whatever it was told would show here.
        'one store' => [fn () => [PermissionScope::store(StoreId::fromString(Fx::storeId('sa'))), Fx::storeId('sa')]],
        'every store' => [fn () => [PermissionScope::allStores(), 'all stores']],
    ]);
});

describe('a module deleting inside its own transaction', function () {
    it('keeps the row and the file, and announces nothing, when the caller rolls back', function () {
        Fx::actAsCustomer(Fx::customer());
        $mediaId = moduleDeleteUpload();
        $announced = false;
        Event::listen(MediaDeleted::class, function () use (&$announced) {
            $announced = true;
        });

        try {
            DB::transaction(function () use ($mediaId) {
                moduleDeleteFor($mediaId);

                throw new RuntimeException('The caller failed after deleting.');
            });
        } catch (RuntimeException) {
        }

        expect(moduleDeleteRow($mediaId))->toBeTrue()
            ->and(Storage::disk('local')->allFiles())->toHaveCount(1)
            ->and($announced)->toBeFalse();
    });

    it('removes the file only once the caller commits', function () {
        Fx::actAsCustomer(Fx::customer());
        $mediaId = moduleDeleteUpload();
        $announced = false;
        Event::listen(MediaDeleted::class, function () use (&$announced) {
            $announced = true;
        });
        $during = null;

        DB::transaction(function () use ($mediaId, &$during, &$announced) {
            moduleDeleteFor($mediaId);
            $during = [count(Storage::disk('local')->allFiles()), $announced];
        });

        expect($during)->toBe([1, false])
            ->and(moduleDeleteRow($mediaId))->toBeFalse()
            ->and(Storage::disk('local')->allFiles())->toBe([])
            ->and($announced)->toBeTrue();
    });
});
