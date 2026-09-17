<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrency;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrencyHandler;
use Modules\Platform\Application\Command\CreateStore\CreateStore;
use Modules\Platform\Application\Command\CreateStore\CreateStoreHandler;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMedia;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMediaHandler;
use Modules\Platform\Application\Command\GenerateMediaVariants\GenerateMediaVariants;
use Modules\Platform\Application\Command\GenerateMediaVariants\GenerateMediaVariantsHandler;
use Modules\Platform\Application\Command\RequeueStuckMediaVariants\RequeueStuckMediaVariants;
use Modules\Platform\Application\Command\RequeueStuckMediaVariants\RequeueStuckMediaVariantsHandler;
use Modules\Platform\Application\Command\RetryMediaVariants\RetryMediaVariants;
use Modules\Platform\Application\Command\RetryMediaVariants\RetryMediaVariantsHandler;
use Modules\Platform\Application\Command\UpdateCurrency\UpdateCurrency;
use Modules\Platform\Application\Command\UpdateCurrency\UpdateCurrencyHandler;
use Modules\Platform\Application\Command\UpdateMediaAltText\UpdateMediaAltText;
use Modules\Platform\Application\Command\UpdateMediaAltText\UpdateMediaAltTextHandler;
use Modules\Platform\Application\Command\UpdateStore\UpdateStore;
use Modules\Platform\Application\Command\UpdateStore\UpdateStoreHandler;
use Modules\Platform\Application\Command\UploadMedia\UploadMedia;
use Modules\Platform\Application\Command\UploadMedia\UploadMediaHandler;
use Modules\Platform\Infrastructure\Queue\GenerateMediaVariantsJob;
use Modules\Platform\Infrastructure\SystemActorContext;
use Modules\Platform\Infrastructure\SystemOnlyAuthorizer;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Enums\MediaVisibility;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

final class PermissionLog implements Authorizer
{
    /** @var list<array{string, string}> */
    public array $checks = [];

    public bool $deny = false;

    public function authorize(string $permission, PermissionScope $scope): void
    {
        $this->checks[] = [$permission, $scope->describe()];

        if ($this->deny) {
            throw new Unauthorized($permission);
        }
    }

    public function storesWith(string $permission): ?array
    {
        return $this->deny ? [] : null;
    }
}

function permissionLog(): PermissionLog
{
    $log = new PermissionLog;
    app()->instance(Authorizer::class, $log);

    return $log;
}

/**
 * A media row written directly, so creating it checks no permission.
 */
function mediaRowWithoutPermission(?string $variantsStatus = 'FAILED'): string
{
    $id = strtolower((string) Str::ulid());

    DB::table('platform.media')->insert([
        'id' => $id,
        'visibility' => 'PUBLIC',
        'disk' => 'local',
        'object_key' => "media/{$id}.jpg",
        'original_filename' => 'hinge.jpg',
        'mime' => 'image/jpeg',
        'bytes' => 1000,
        'width' => 10,
        'height' => 10,
        'checksum' => hash('sha256', $id),
        'variants_status' => $variantsStatus,
        'variants_queued_at' => $variantsStatus === null ? null : now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

beforeEach(function () {
    seed(PlatformSeeder::class);
    Storage::fake('public');
    Storage::fake('local');
    Queue::fake();
});

it('checks the right permission, against the right scope, in every handler', function (Closure $run, string $permission, string $scope) {
    $log = permissionLog();

    $run();

    // 'global' = nothing store-related; 'all stores' = must hold it everywhere; otherwise one store.
    $expected = in_array($scope, ['global', 'all stores'], true) ? $scope : (string) app(PlatformApi::class)->storeByCode($scope)?->id;
    expect($log->checks)->toBe([[$permission, $expected]]);
})->with([
    'create a currency' => [fn () => app(CreateCurrencyHandler::class)->handle(new CreateCurrency('XTS', 2, 'عملة', 'Currency', 'ع', 'XTS', null)), 'platform.currency.create', 'global'],
    'update a currency' => [fn () => app(UpdateCurrencyHandler::class)->handle(new UpdateCurrency('SAR', nameEn: 'Riyal')), 'platform.currency.update', 'global'],
    'create a store' => [fn () => app(CreateStoreHandler::class)->handle(new CreateStore('xa', 'متجر', 'Store', 'XA', 'SAR', 1500, 'UTC', 9)), 'platform.store.create', 'global'],
    // Per-store: an admin of one store must not be able to edit another.
    'update a store' => [fn () => app(UpdateStoreHandler::class)->handle(new UpdateStore('eg', taxRateBasisPoints: 1500)), 'platform.store.update', 'eg'],
    // Media is global: no store is checked.
    'upload media' => [function () {
        $path = sys_get_temp_dir().'/tw-auth-'.uniqid().'.jpg';
        imagejpeg(imagecreatetruecolor(20, 20), $path);

        try {
            app(UploadMediaHandler::class)->handle(new UploadMedia(MediaVisibility::Public, $path, 'hinge.jpg'));
        } finally {
            unlink($path);
        }
    }, 'platform.media.upload', 'global'],
    'change alt text' => [fn () => app(UpdateMediaAltTextHandler::class)->handle(new UpdateMediaAltText(mediaRowWithoutPermission(), 'مفصلة', 'Hinge')), 'platform.media.update', 'global'],
    'delete media' => [fn () => app(DeleteMediaHandler::class)->handle(new DeleteMedia(mediaRowWithoutPermission())), 'platform.media.delete', 'global'],
    'retry variants' => [fn () => app(RetryMediaVariantsHandler::class)->handle(new RetryMediaVariants(mediaRowWithoutPermission())), 'platform.media.upload', 'global'],
    'generate variants' => [fn () => app(GenerateMediaVariantsHandler::class)->handle(new GenerateMediaVariants(mediaRowWithoutPermission('READY'))), 'platform.media.variants.generate', 'global'],
    'mark variants failed' => [fn () => app(GenerateMediaVariantsHandler::class)->fail(new GenerateMediaVariants(mediaRowWithoutPermission('READY'))), 'platform.media.variants.generate', 'global'],
    'sweep stuck variants' => [fn () => app(RequeueStuckMediaVariantsHandler::class)->handle(new RequeueStuckMediaVariants), 'platform.media.variants.generate', 'global'],
]);

it('changes nothing and audits nothing when the permission is denied', function (Closure $run) {
    permissionLog()->deny = true;
    $auditsBefore = DB::table('platform.audit_entries')->count();
    $storesBefore = DB::table('platform.stores')->get()->toJson();

    expect($run)->toThrow(Unauthorized::class)
        ->and(DB::table('platform.audit_entries')->count())->toBe($auditsBefore)
        ->and(DB::table('platform.stores')->get()->toJson())->toBe($storesBefore);
})->with([
    'update a store' => [fn () => app(UpdateStoreHandler::class)->handle(new UpdateStore('sa', taxRateBasisPoints: 1))],
    'create a store' => [fn () => app(CreateStoreHandler::class)->handle(new CreateStore('xa', 'متجر', 'Store', 'XA', 'SAR', 1500, 'UTC', 9))],
]);

it('changes no media, stores no file and queues nothing when the permission is denied', function (Closure $run) {
    $failed = mediaRowWithoutPermission();
    permissionLog()->deny = true;
    $mediaBefore = DB::table('platform.media')->orderBy('id')->get()->toJson();

    expect($run)->toThrow(Unauthorized::class)
        ->and(DB::table('platform.media')->orderBy('id')->get()->toJson())->toBe($mediaBefore)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(DB::table('platform.audit_entries')->where('subject_type', 'platform.media')->count())->toBe(0);
    Queue::assertNotPushed(GenerateMediaVariantsJob::class);
})->with([
    'upload media' => [function () {
        $path = sys_get_temp_dir().'/tw-auth-'.uniqid().'.jpg';
        imagejpeg(imagecreatetruecolor(20, 20), $path);

        try {
            app(UploadMediaHandler::class)->handle(new UploadMedia(MediaVisibility::Public, $path, 'hinge.jpg'));
        } finally {
            unlink($path);
        }
    }],
    'delete media' => [fn () => app(DeleteMediaHandler::class)->handle(new DeleteMedia((string) DB::table('platform.media')->value('id')))],
    'retry variants' => [fn () => app(RetryMediaVariantsHandler::class)->handle(new RetryMediaVariants((string) DB::table('platform.media')->value('id')))],
]);

it('refuses every web request until Access exists, even though nobody has logged in', function () {
    expect(fn () => (new SystemOnlyAuthorizer(new SystemActorContext, runningInConsole: false))->authorize('platform.media.upload', PermissionScope::global()))
        ->toThrow(Unauthorized::class);

    (new SystemOnlyAuthorizer(new SystemActorContext, runningInConsole: true))->authorize('platform.media.upload', PermissionScope::global());

    // The system acts everywhere, so it is never limited to a list of stores.
    expect((new SystemOnlyAuthorizer(new SystemActorContext, runningInConsole: true))->storesWith('platform.store.update'))->toBeNull()
        ->and((new SystemOnlyAuthorizer(new SystemActorContext, runningInConsole: false))->storesWith('platform.store.update'))->toBe([]);
});

it('binds the interim authorizer so that a real handler refuses a web request', function () {
    // Tests run in the console; pretend this one serves a web request, as PHP-FPM would.
    $console = new ReflectionProperty(app(), 'isRunningInConsole');
    $console->setValue(app(), false);
    app()->forgetScopedInstances();

    try {
        expect(fn () => app(RetryMediaVariantsHandler::class)->handle(new RetryMediaVariants(mediaRowWithoutPermission())))
            ->toThrow(Unauthorized::class);
    } finally {
        $console->setValue(app(), true);
        app()->forgetScopedInstances();
    }
});
