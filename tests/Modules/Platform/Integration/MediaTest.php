<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMedia;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMediaHandler;
use Modules\Platform\Application\Command\GenerateMediaVariants\GenerateMediaVariantsHandler;
use Modules\Platform\Application\Command\RequeueStuckMediaVariants\RequeueStuckMediaVariants;
use Modules\Platform\Application\Command\RequeueStuckMediaVariants\RequeueStuckMediaVariantsHandler;
use Modules\Platform\Application\Command\RetryMediaVariants\RetryMediaVariants;
use Modules\Platform\Application\Command\RetryMediaVariants\RetryMediaVariantsHandler;
use Modules\Platform\Application\Command\UpdateMediaAltText\UpdateMediaAltText;
use Modules\Platform\Application\Command\UpdateMediaAltText\UpdateMediaAltTextHandler;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Application\Command\UploadMedia\UploadMedia;
use Modules\Platform\Application\Command\UploadMedia\UploadMediaHandler;
use Modules\Platform\Application\Media\ImageVariantGenerator;
use Modules\Platform\Application\Media\MediaSettings;
use Modules\Platform\Domain\Exception\InvalidMediaVariantsTransition;
use Modules\Platform\Domain\Exception\MediaInUse;
use Modules\Platform\Domain\Exception\MediaNotFound;
use Modules\Platform\Domain\Exception\MediaTooLarge;
use Modules\Platform\Domain\Exception\UnsupportedMediaType;
use Modules\Platform\Domain\Model\Media;
use Modules\Platform\Domain\Repository\MediaRepository;
use Modules\Platform\Infrastructure\External\InterventionImageVariantGenerator;
use Modules\Platform\Infrastructure\External\LaravelMediaStorage;
use Modules\Platform\Infrastructure\Queue\GenerateMediaVariantsJob;
use Modules\Platform\Presentation\Console\RequeueStuckMediaVariantsCommand;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Enums\ImageFormat;
use Modules\Platform\Public\Enums\MediaSize;
use Modules\Platform\Public\Enums\MediaVariantsStatus;
use Modules\Platform\Public\Enums\MediaVisibility;
use Modules\Platform\Public\Events\MediaDeleted;
use Modules\Platform\Public\Events\MediaVariantsReady;
use Psr\Log\NullLogger;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\get;
use function Pest\Laravel\travel;

uses(RefreshDatabase::class);

/*
| The "local" disk is private: it keeps every original and every private file. The "public" disk
| sits behind the CDN and holds only the variants of public images.
*/

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local', ['serve' => true]);
    Queue::fake();
});

afterEach(function () {
    File::deleteDirectory(mediaTestDirectory());
});

function mediaTestDirectory(): string
{
    return sys_get_temp_dir().'/tw-media-tests';
}

function mediaTestPath(string $name): string
{
    File::ensureDirectoryExists(mediaTestDirectory());

    return mediaTestDirectory().'/'.uniqid().'-'.$name;
}

/**
 * A real image file of the given size. The shade makes files with the same size differ.
 *
 * @param  positive-int  $width
 * @param  positive-int  $height
 * @param  'jpeg'|'png'|'webp'  $type
 * @param  int<0, 255>  $shade
 */
function imageFile(int $width, int $height, string $type = 'jpeg', int $shade = 120, ?string $name = null): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, $shade, 80, 40));
    $path = mediaTestPath($name ?? "image.{$type}");

    match ($type) {
        'jpeg' => imagejpeg($image, $path),
        'png' => imagepng($image, $path),
        'webp' => imagewebp($image, $path),
    };

    return $path;
}

function pdfFile(string $name = 'document.pdf', string $marker = ''): string
{
    $path = mediaTestPath($name);
    file_put_contents($path, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%{$marker}\n%%EOF\n");

    return $path;
}

/**
 * Writes an EXIF block holding only an orientation into a JPEG, as a phone camera does.
 */
function withExifOrientation(string $jpegPath, int $orientation): string
{
    $tiff = "II*\x00".pack('V', 8).pack('v', 1).pack('vvVvv', 0x0112, 3, 1, $orientation, 0).pack('V', 0);
    $payload = "Exif\x00\x00".$tiff;
    $jpeg = (string) file_get_contents($jpegPath);

    file_put_contents($jpegPath, substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2));

    return $jpegPath;
}

function uploadMedia(string $path, MediaVisibility $visibility = MediaVisibility::Public, string $name = 'hinge.jpg'): string
{
    return app(UploadMediaHandler::class)->handle(new UploadMedia($visibility, $path, $name));
}

function runQueuedVariants(string $mediaId): void
{
    (new GenerateMediaVariantsJob($mediaId))->handle(app(GenerateMediaVariantsHandler::class));
}

function mediaRow(string $id): stdClass
{
    $row = DB::table('platform.media')->where('id', $id)->first();

    return $row instanceof stdClass ? $row : throw new LogicException("Media {$id} does not exist.");
}

function variantBase(string $id): string
{
    return (string) preg_replace('/\.[a-z]+\z/', '', (string) mediaRow($id)->object_key);
}

describe('uploading', function () {
    it('keeps the original on the private disk, records the media and audits the upload', function () {
        $id = uploadMedia(imageFile(1600, 900), name: 'C:\\photos\\hinge.jpg');
        $row = mediaRow($id);

        Storage::disk('local')->assertExists((string) $row->object_key);
        expect(Storage::disk('public')->allFiles())->toBe([])
            ->and($row->disk)->toBe('local')
            ->and($row->visibility)->toBe('PUBLIC')
            ->and($row->mime)->toBe('image/jpeg')
            ->and((int) $row->width)->toBe(1600)
            ->and($row->original_filename)->toBe('hinge.jpg')
            ->and($row->variants_status)->toBe('PENDING')
            ->and($row->variants_queued_at)->not->toBeNull();

        $audit = DB::table('platform.audit_entries')->where('action', 'platform.media.uploaded')->where('subject_id', $id)->first();
        expect(json_decode((string) $audit?->changes, true)['original_filename'])->toBe('changed');
    });

    it('queues variant generation for a public image only, and only once the upload commits', function () {
        $imageId = uploadMedia(imageFile(800, 600));
        uploadMedia(pdfFile(), MediaVisibility::Private, 'cr.pdf');
        uploadMedia(imageFile(800, 600, shade: 10), MediaVisibility::Private, 'receipt.jpg');

        Queue::assertPushed(GenerateMediaVariantsJob::class, 1);
        // Queue::fake() records a job at once, so the after-commit flag is what proves the timing.
        Queue::assertPushed(GenerateMediaVariantsJob::class, fn (GenerateMediaVariantsJob $job): bool => $job->mediaId === $imageId && $job->afterCommit === true);
    });

    it('returns the existing media for an identical public image, without storing it twice', function () {
        $path = imageFile(400, 300);

        $first = uploadMedia($path);
        $second = uploadMedia($path, name: 'same-photo-new-name.jpg');

        expect($second)->toBe($first)
            ->and(DB::table('platform.media')->count())->toBe(1)
            ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    });

    it('never shares a private file, even when two uploads are identical', function () {
        $path = pdfFile();

        $first = uploadMedia($path, MediaVisibility::Private, 'company-a-receipt.pdf');
        $second = uploadMedia($path, MediaVisibility::Private, 'company-b-receipt.pdf');

        expect($second)->not->toBe($first)
            ->and(app(PlatformApi::class)->media($second)?->originalFilename)->toBe('company-b-receipt.pdf')
            ->and(Storage::disk('local')->allFiles())->toHaveCount(2);
    });

    it('stores the same bytes separately as public and as private', function () {
        $path = imageFile(400, 300, 'png');

        expect(uploadMedia($path, MediaVisibility::Public))->not->toBe(uploadMedia($path, MediaVisibility::Private));
    });

    it('keeps the identical image a concurrent upload stored first, and removes its own copy', function () {
        $path = imageFile(400, 300);
        $first = uploadMedia($path);

        // The second upload's duplicate check misses, as if both uploads checked at the same moment.
        $real = app(MediaRepository::class);
        app()->instance(MediaRepository::class, new class($real) implements MediaRepository
        {
            private bool $missed = false;

            public function __construct(private readonly MediaRepository $real) {}

            public function nextId(): string
            {
                return $this->real->nextId();
            }

            public function byId(string $id): ?Media
            {
                return $this->real->byId($id);
            }

            public function lockById(string $id): ?Media
            {
                return $this->real->lockById($id);
            }

            public function publicByChecksum(string $checksum): ?Media
            {
                if (! $this->missed) {
                    $this->missed = true;

                    return null;
                }

                return $this->real->publicByChecksum($checksum);
            }

            public function stalePendingIds(DateTimeImmutable $queuedBefore, int $limit): array
            {
                return $this->real->stalePendingIds($queuedBefore, $limit);
            }

            public function add(Media $media): bool
            {
                return $this->real->add($media);
            }

            public function update(Media $media): void
            {
                $this->real->update($media);
            }

            public function delete(Media $media): void
            {
                $this->real->delete($media);
            }
        });

        expect(uploadMedia($path))->toBe($first)
            ->and(DB::table('platform.media')->count())->toBe(1)
            ->and(Storage::disk('local')->allFiles())->toBe([(string) mediaRow($first)->object_key]);
    });

    it('detects the type from the contents, not the file name', function () {
        $id = uploadMedia(imageFile(400, 300, 'png', name: 'photo.jpg'), name: 'photo.jpg');

        expect(mediaRow($id)->mime)->toBe('image/png')
            ->and((string) mediaRow($id)->object_key)->toEndWith('.png');
    });

    it('refuses a PDF renamed .jpg as a public image, because it is a PDF', function () {
        $refused = null;

        try {
            uploadMedia(pdfFile('not-really.jpg'), name: 'not-really.jpg');
        } catch (UnsupportedMediaType $error) {
            $refused = $error;
        }

        expect($refused?->mime)->toBe('application/pdf')
            ->and(DB::table('platform.media')->count())->toBe(0)
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('records a sideways phone photo with its upright width and height', function () {
        // Stored 300×200, displayed turned a quarter (EXIF orientation 6): 200 wide, 300 tall.
        $id = uploadMedia(withExifOrientation(imageFile(300, 200), 6));

        expect([app(PlatformApi::class)->media($id)?->width, app(PlatformApi::class)->media($id)?->height])->toBe([200, 300]);

        runQueuedVariants($id);
        $zoom = getimagesizefromstring((string) Storage::disk('public')->get(variantBase($id).'/zoom.jpg'));

        expect([$zoom[0] ?? null, $zoom[1] ?? null])->toBe([200, 300]);
    });

    it('refuses an animated WebP, which cannot be resized', function () {
        // RIFF header, then a VP8X chunk with the animation flag set and a 100×100 canvas.
        $path = mediaTestPath('animated.webp');
        file_put_contents($path, 'RIFF'.pack('V', 22).'WEBP'.'VP8X'.pack('V', 10)."\x02\x00\x00\x00"."\x63\x00\x00"."\x63\x00\x00");

        expect(fn () => uploadMedia($path, name: 'animated.webp'))->toThrow(UnsupportedMediaType::class, 'animated');
    });

    it('applies each visibility\'s upload limit from settings', function (string $key, Closure $upload) {
        app(UpdateSettingHandler::class)->handle(new UpdateSetting($key, null, 100));

        expect($upload)->toThrow(MediaTooLarge::class)
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    })->with([
        'public' => [MediaSettings::MAX_PUBLIC_BYTES, fn () => uploadMedia(imageFile(400, 300))],
        'private' => [MediaSettings::MAX_PRIVATE_BYTES, fn () => uploadMedia(pdfFile(marker: str_repeat('x', 200)), MediaVisibility::Private, 'cr.pdf')],
    ]);

    it('declares both upload limits with a 10 MB default', function () {
        expect(app(PlatformApi::class)->setting(MediaSettings::MAX_PUBLIC_BYTES)->int())->toBe(10 * 1024 * 1024)
            ->and(app(PlatformApi::class)->setting(MediaSettings::MAX_PRIVATE_BYTES)->int())->toBe(10 * 1024 * 1024);
    });

    it('refuses an image too tall to process safely', function () {
        uploadMedia(imageFile(1, 12001, 'png'));
    })->throws(MediaTooLarge::class);

    it('removes the stored file when the row cannot be written', function () {
        DB::statement('ALTER TABLE platform.media ADD CONSTRAINT testing_refuse_all CHECK (false) NOT VALID');

        expect(fn () => uploadMedia(imageFile(400, 300)))->toThrow(QueryException::class)
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('removes the stored file when the caller\'s own transaction rolls back after the upload', function () {
        try {
            DB::transaction(function () {
                uploadMedia(imageFile(400, 300));

                throw new RuntimeException('The caller failed after uploading.');
            });
        } catch (RuntimeException) {
        }

        expect(DB::table('platform.media')->count())->toBe(0)
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    });
});

describe('variants', function () {
    it('writes every size in every real format to the public disk, and announces it', function () {
        Event::fake([MediaVariantsReady::class]);
        $id = uploadMedia(imageFile(3000, 2000));

        runQueuedVariants($id);

        $row = mediaRow($id);
        $base = variantBase($id);
        $finfo = new finfo(FILEINFO_MIME_TYPE);

        foreach (MediaSize::cases() as $size) {
            foreach (ImageFormat::cases() as $format) {
                $contents = (string) Storage::disk('public')->get("{$base}/{$size->slug()}.{$format->extension()}");

                expect($finfo->buffer($contents))->toBe($format->mime(), "{$size->slug()}.{$format->extension()} must really be {$format->mime()}");
            }
        }

        expect(Storage::disk('public')->allFiles())->toHaveCount(12)
            ->and($row->variants_status)->toBe('READY')
            ->and($row->variants_generated_at)->not->toBeNull();
        Event::assertDispatched(MediaVariantsReady::class, fn (MediaVariantsReady $event): bool => $event->mediaId === $id);
    });

    it('keeps the whole image and limits only its longest side', function () {
        $id = uploadMedia(imageFile(3000, 2000));
        runQueuedVariants($id);

        $card = getimagesizefromstring((string) Storage::disk('public')->get(variantBase($id).'/card.jpg'));
        $thumb = getimagesizefromstring((string) Storage::disk('public')->get(variantBase($id).'/thumb.webp'));

        expect([$card[0] ?? null, $card[1] ?? null])->toBe([600, 400])
            ->and([$thumb[0] ?? null, $thumb[1] ?? null])->toBe([200, 133]);
    });

    it('never enlarges a smaller original', function () {
        $id = uploadMedia(imageFile(150, 100));
        runQueuedVariants($id);

        $zoom = getimagesizefromstring((string) Storage::disk('public')->get(variantBase($id).'/zoom.jpg'));

        expect([$zoom[0] ?? null, $zoom[1] ?? null])->toBe([150, 100]);
    });

    it('turns transparency white in JPEG', function () {
        $image = imagecreatetruecolor(50, 50);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        $path = mediaTestPath('transparent.png');
        imagepng($image, $path);

        $id = uploadMedia($path, name: 'transparent.png');
        runQueuedVariants($id);

        $jpeg = imagecreatefromstring((string) Storage::disk('public')->get(variantBase($id).'/thumb.jpg'));
        $pixel = imagecolorsforindex($jpeg ?: throw new LogicException('Not a JPEG.'), (int) imagecolorat($jpeg, 25, 25));

        expect(min($pixel['red'], $pixel['green'], $pixel['blue']))->toBeGreaterThan(240);
    });

    it('runs at most three attempts within the queue\'s retry window, then marks the image FAILED', function () {
        $id = uploadMedia(imageFile(800, 600));
        $job = new GenerateMediaVariantsJob($id);

        expect($job->tries)->toBe(3)
            ->and($job->timeout)->toBeLessThan((int) config('queue.connections.redis.retry_after'));

        $job->failed(new RuntimeException('encoder crashed'));

        expect(mediaRow($id)->variants_status)->toBe('FAILED');
    });

    it('lets staff retry a failed image, which is audited, queued again and can then succeed', function () {
        $id = uploadMedia(imageFile(800, 600));
        (new GenerateMediaVariantsJob($id))->failed(new RuntimeException('encoder crashed'));

        app(RetryMediaVariantsHandler::class)->handle(new RetryMediaVariants($id));

        expect(mediaRow($id)->variants_status)->toBe('PENDING');
        Queue::assertPushed(GenerateMediaVariantsJob::class, 2);
        assertDatabaseHas('platform.audit_entries', ['action' => 'platform.media.variants_retried', 'subject_id' => $id]);

        runQueuedVariants($id);
        expect(mediaRow($id)->variants_status)->toBe('READY');
    });

    it('lets staff retry an image stuck in PENDING for 15 minutes, but not a recent one', function () {
        $id = uploadMedia(imageFile(800, 600));

        expect(fn () => app(RetryMediaVariantsHandler::class)->handle(new RetryMediaVariants($id)))->toThrow(InvalidMediaVariantsTransition::class);

        travel(15)->minutes();
        app(RetryMediaVariantsHandler::class)->handle(new RetryMediaVariants($id));

        Queue::assertPushed(GenerateMediaVariantsJob::class, 2);
    });

    it('reports retrying media that does not exist', function () {
        app(RetryMediaVariantsHandler::class)->handle(new RetryMediaVariants('01j8z3k4m5n6p7q8r9s0t1v2w3'));
    })->throws(MediaNotFound::class);

    it('does nothing when run again after it succeeded', function () {
        $id = uploadMedia(imageFile(800, 600));
        runQueuedVariants($id);
        $generatedAt = mediaRow($id)->variants_generated_at;

        app()->instance(ImageVariantGenerator::class, new class implements ImageVariantGenerator
        {
            public function variants(string $original): iterable
            {
                throw new LogicException('Must not run again.');
            }
        });
        runQueuedVariants($id);

        expect(mediaRow($id)->variants_generated_at)->toBe($generatedAt);
    });

    it('leaves no variant behind when the media is deleted while they are being written', function () {
        $id = uploadMedia(imageFile(800, 600));

        // Deletes the media after the first variant is written, as a staff member could mid-run.
        app()->instance(ImageVariantGenerator::class, new class($id) implements ImageVariantGenerator
        {
            public function __construct(private readonly string $mediaId) {}

            public function variants(string $original): iterable
            {
                $deleted = false;

                foreach ((new InterventionImageVariantGenerator)->variants($original) as $variant) {
                    yield $variant;

                    if (! $deleted) {
                        app(DeleteMediaHandler::class)->handle(new DeleteMedia($this->mediaId));
                        $deleted = true;
                    }
                }
            }
        });

        runQueuedVariants($id);

        expect(DB::table('platform.media')->where('id', $id)->exists())->toBeFalse()
            ->and(Storage::disk('public')->allFiles())->toBe([])
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    });
});

describe('the stuck-variants sweep', function () {
    it('queues again only images PENDING for 15 minutes or more', function () {
        // Shades far apart: near-identical flat colours can compress to the same JPEG bytes and be deduplicated.
        $stuck = uploadMedia(imageFile(800, 600, shade: 10));
        $failed = uploadMedia(imageFile(800, 600, shade: 70));
        (new GenerateMediaVariantsJob($failed))->failed(new RuntimeException('encoder crashed'));
        $ready = uploadMedia(imageFile(800, 600, shade: 130));
        runQueuedVariants($ready);

        travel(10)->minutes();
        $recent = uploadMedia(imageFile(800, 600, shade: 190));
        travel(5)->minutes();

        expect(app(RequeueStuckMediaVariantsHandler::class)->handle(new RequeueStuckMediaVariants))->toBe(1)
            ->and(mediaRow($failed)->variants_status)->toBe('FAILED')
            ->and(mediaRow($recent)->variants_status)->toBe('PENDING');

        Queue::assertPushed(GenerateMediaVariantsJob::class, fn (GenerateMediaVariantsJob $job): bool => $job->mediaId === $stuck);
        // Four uploads queued once each; only the stuck one was queued a second time.
        Queue::assertPushed(GenerateMediaVariantsJob::class, 5);

        // Queued again just now, so it is not stuck on the next run.
        expect(app(RequeueStuckMediaVariantsHandler::class)->handle(new RequeueStuckMediaVariants))->toBe(0);
    });

    it('runs from the console every ten minutes', function () {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, RequeueStuckMediaVariantsCommand::NAME));

        expect($events)->toHaveCount(1)
            ->and($events->first()?->expression)->toBe('*/10 * * * *');

        expect(Artisan::call(RequeueStuckMediaVariantsCommand::NAME))->toBe(0)
            ->and(Artisan::output())->toContain('0 stuck image(s)');
    });
});

describe('reading', function () {
    it('gives a public image variant URLs once they are ready, and never its original', function () {
        $id = uploadMedia(imageFile(800, 600));

        $before = app(PlatformApi::class)->mediaUrls($id);
        runQueuedVariants($id);
        $after = app(PlatformApi::class)->mediaUrls($id);

        expect($before?->variants)->toBe([])
            ->and($before?->original)->toBeNull()
            ->and($before?->expiresAt)->toBeNull()
            ->and($after?->original)->toBeNull()
            ->and($after?->variants['card']['avif'] ?? null)->toEndWith('/card.avif')
            ->and(array_keys($after->variants ?? []))->toBe(['thumb', 'card', 'detail', 'zoom']);
    });

    it('gives private media a signed link that works for 30 minutes and then stops', function () {
        // Storage::fake() replaces temporary links with unsigned fakes, so this test uses the real
        // local disk — pointed at a scratch folder — to exercise real signing and serving.
        $root = storage_path('framework/testing/disks/private-links');
        File::deleteDirectory($root);
        config(['filesystems.disks.local.root' => $root]);
        Storage::forgetDisk('local');

        try {
            $id = uploadMedia(pdfFile(), MediaVisibility::Private, 'commercial-registration.pdf');
            $urls = app(PlatformApi::class)->mediaUrls($id) ?? throw new LogicException('No URLs.');

            expect($urls->variants)->toBe([])
                ->and($urls->expiresAt?->diffInMinutes(now()->toImmutable(), true))->toEqualWithDelta(30, 0.1)
                ->and($urls->original)->toContain('signature=');

            get((string) $urls->original)->assertOk();

            travel(31)->minutes();

            get((string) $urls->original)->assertForbidden();
        } finally {
            File::deleteDirectory($root);
        }
    });

    it('asks object storage to download a private file under its original name', function () {
        Storage::disk('local')->buildTemporaryUrlsUsing(
            fn (string $path, DateTimeInterface $expiresAt, array $options): string => 'https://storage.test/'.$path.'?'.http_build_query($options),
        );

        $id = uploadMedia(pdfFile(), MediaVisibility::Private, 'سجل تجاري.pdf');
        $link = (string) app(PlatformApi::class)->mediaUrls($id)?->original;
        parse_str((string) parse_url($link, PHP_URL_QUERY), $options);

        expect($options['ResponseContentDisposition'] ?? null)
            ->toBe("attachment; filename=\"_________.pdf\"; filename*=UTF-8''".rawurlencode('سجل تجاري.pdf'));
    });

    it('never puts an original or a private file on the public disk', function () {
        uploadMedia(pdfFile(), MediaVisibility::Private, 'receipt.pdf');
        uploadMedia(imageFile(400, 300));

        expect(Storage::disk('public')->allFiles())->toBe([])
            ->and(Storage::disk('local')->allFiles())->toHaveCount(2);
    });

    it('describes media to other modules as a DTO, and knows nothing of unknown ids', function () {
        $id = uploadMedia(imageFile(640, 480, 'webp'));
        $media = app(PlatformApi::class)->media($id);

        expect($media?->mime)->toBe('image/webp')
            ->and($media?->width)->toBe(640)
            ->and($media?->variantsStatus)->toBe(MediaVariantsStatus::Pending)
            ->and(app(PlatformApi::class)->media('01j8z3k4m5n6p7q8r9s0t1v2w3'))->toBeNull()
            ->and(app(PlatformApi::class)->media("not a ulid \xC3\x28"))->toBeNull()
            ->and(app(PlatformApi::class)->mediaUrls('../../etc/passwd'))->toBeNull();
    });

    it('reads without locking the row, so pages never wait on or block a change', function () {
        $id = uploadMedia(imageFile(640, 480));

        DB::enableQueryLog();
        app(PlatformApi::class)->media($id);
        app(PlatformApi::class)->mediaUrls($id);
        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        expect($queries)->toContain('platform')
            ->and(strtolower($queries))->not->toContain('for update');
    });
});

describe('alt text', function () {
    it('changes and audits the alt text', function () {
        $id = uploadMedia(imageFile(640, 480));

        app(UpdateMediaAltTextHandler::class)->handle(new UpdateMediaAltText($id, 'مفصلة خزانة', 'Cabinet hinge'));

        expect(app(PlatformApi::class)->media($id)?->altEn)->toBe('Cabinet hinge');
        assertDatabaseHas('platform.audit_entries', ['action' => 'platform.media.alt_text_changed', 'subject_id' => $id]);
    });

    it('reports media that does not exist', function () {
        app(UpdateMediaAltTextHandler::class)->handle(new UpdateMediaAltText('01j8z3k4m5n6p7q8r9s0t1v2w3', 'x', 'x'));
    })->throws(MediaNotFound::class);
});

describe('deleting', function () {
    it('deletes the row, then the original and every variant, and announces it', function () {
        Event::fake([MediaDeleted::class]);
        $id = uploadMedia(imageFile(800, 600));
        runQueuedVariants($id);
        expect(Storage::disk('public')->allFiles())->toHaveCount(12)
            ->and(Storage::disk('local')->allFiles())->toHaveCount(1);

        app(DeleteMediaHandler::class)->handle(new DeleteMedia($id));

        expect(DB::table('platform.media')->where('id', $id)->exists())->toBeFalse()
            ->and(Storage::disk('public')->allFiles())->toBe([])
            ->and(Storage::disk('local')->allFiles())->toBe([]);
        Event::assertDispatched(MediaDeleted::class, fn (MediaDeleted $event): bool => $event->mediaId === $id);
        assertDatabaseHas('platform.audit_entries', ['action' => 'platform.media.deleted', 'subject_id' => $id]);
    });

    it('deletes a private file', function () {
        $id = uploadMedia(pdfFile(), MediaVisibility::Private, 'receipt.pdf');

        app(DeleteMediaHandler::class)->handle(new DeleteMedia($id));

        expect(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('keeps the files, and announces nothing, when the caller\'s transaction rolls back', function () {
        $id = uploadMedia(imageFile(800, 600));
        $announced = false;
        Event::listen(MediaDeleted::class, function () use (&$announced) {
            $announced = true;
        });

        try {
            DB::transaction(function () use ($id) {
                app(DeleteMediaHandler::class)->handle(new DeleteMedia($id));

                throw new RuntimeException('The caller failed after deleting.');
            });
        } catch (RuntimeException) {
        }

        expect(DB::table('platform.media')->where('id', $id)->exists())->toBeTrue()
            ->and(Storage::disk('local')->allFiles())->toHaveCount(1)
            ->and($announced)->toBeFalse();
    });

    it('refuses to delete media another module still references, and keeps its files', function () {
        Schema::create('testing_media_refs', function (Blueprint $table) {
            $table->id();
            $table->char('media_id', 26);
            $table->foreign('media_id')->references('id')->on('platform.media')->restrictOnDelete();
        });
        $id = uploadMedia(imageFile(800, 600));
        DB::table('testing_media_refs')->insert(['media_id' => $id]);

        expect(fn () => app(DeleteMediaHandler::class)->handle(new DeleteMedia($id)))->toThrow(MediaInUse::class)
            ->and(DB::table('platform.media')->where('id', $id)->exists())->toBeTrue()
            ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    });

    it('reports deleting media that does not exist', function () {
        app(DeleteMediaHandler::class)->handle(new DeleteMedia('01j8z3k4m5n6p7q8r9s0t1v2w3'));
    })->throws(MediaNotFound::class);
});

describe('the environment', function () {
    it('refuses one disk for both public variants and private files', function () {
        new LaravelMediaStorage(app(Filesystems::class), new NullLogger, ['public_disk' => 'public', 'private_disk' => 'public']);
    })->throws(InvalidArgumentException::class);

    it('has an image library that can write AVIF, WebP and JPEG and read EXIF', function () {
        $gd = gd_info();

        expect($gd['AVIF Support'] ?? false)->toBeTrue('GD must be built with AVIF support.')
            ->and($gd['WebP Support'] ?? false)->toBeTrue('GD must be built with WebP support.')
            ->and($gd['JPEG Support'] ?? false)->toBeTrue('GD must be built with JPEG support.')
            ->and(function_exists('exif_read_data'))->toBeTrue('The exif extension turns sideways photos upright.');
    });
});
