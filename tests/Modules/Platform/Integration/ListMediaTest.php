<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Platform\Application\Query\ListMedia\ListMedia;
use Modules\Platform\Application\Query\ListMedia\ListMediaHandler;
use Modules\Platform\Application\Query\ListMedia\MediaRow;
use Modules\Platform\Domain\Model\Media;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * A file in the library, written straight in: what is being tested is what comes back out, not the
 * upload that put it there.
 */
function mediaFile(?string $variantsStatus = null, ?string $queuedAt = null, string $createdAt = '2026-09-20 10:00:00+00'): string
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
        // Its own, because identical public files are stored once: the table has a unique index
        // on the checksum, which is the dedupe rule (platform.md 1.4).
        'checksum' => hash('sha256', $id),
        'variants_status' => $variantsStatus,
        // A status and a queued time come together or not at all, by check constraint.
        'variants_queued_at' => $variantsStatus === null ? null : ($queuedAt ?? $createdAt),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    return $id;
}

/**
 * @return list<MediaRow>
 */
function libraryPage(int $perPage = 24): array
{
    return app(ListMediaHandler::class)->handle(new ListMedia(perPage: $perPage))->media;
}

/**
 * Stage 2b, step 3. What the media library is handed (frontend.md §3.5, E5).
 *
 * Media belongs to no store and its permissions are store-free, so the questions are global ones.
 * What matters here is who gets in, and whether a file may be tried again.
 */
describe('the media library', function () {
    it('refuses somebody who may not touch media at all', function () {
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_VIEW], ['sa']));

        expect(fn () => libraryPage())->toThrow(Unauthorized::class);
    });

    it('lets somebody in who may only describe a file, and says what they may do', function () {
        mediaFile();
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::MEDIA_UPDATE], ['sa']));

        $page = app(ListMediaHandler::class)->handle(new ListMedia);

        // Looking is not a permission of its own: whoever may change a file may see the library,
        // and the screen is told which of the three things they may actually do.
        expect($page->media)->toHaveCount(1)
            ->and($page->mayUpdate)->toBeTrue()
            ->and($page->mayUpload)->toBeFalse()
            ->and($page->mayDelete)->toBeFalse();
    });

    it('offers a retry for a failed image', function () {
        mediaFile('FAILED');
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(libraryPage()[0]->retryable)->toBeTrue();
    });

    it('offers a retry for an image left pending too long, and not for a fresh one', function () {
        // The same rule the domain applies when the retry is asked for, read from its own constant.
        $stale = CarbonImmutable::now()->subMinutes(Media::STALE_PENDING_MINUTES + 1);
        mediaFile('PENDING', $stale->toDateTimeString(), '2026-09-20 09:00:00+00');
        mediaFile('PENDING', CarbonImmutable::now()->toDateTimeString(), '2026-09-20 11:00:00+00');
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        // Newest first, so the fresh one comes back first.
        $files = libraryPage();

        expect($files[0]->retryable)->toBeFalse()
            ->and($files[1]->retryable)->toBeTrue();
    });

    it('pages by keyset, newest first, without showing a file twice', function () {
        $ids = [
            mediaFile(createdAt: '2026-09-20 09:00:00+00'),
            mediaFile(createdAt: '2026-09-20 10:00:00+00'),
            mediaFile(createdAt: '2026-09-20 11:00:00+00'),
        ];
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $first = app(ListMediaHandler::class)->handle(new ListMedia(perPage: 2));
        $second = app(ListMediaHandler::class)->handle(new ListMedia($first->nextCreatedAt, $first->nextId, 2));

        $seen = array_map(static fn (MediaRow $file): string => $file->id, [...$first->media, ...$second->media]);

        expect($first->media)->toHaveCount(2)
            ->and($seen)->toBe(array_unique($seen))
            ->and(array_intersect($ids, $seen))->toHaveCount(3);
    });
});
