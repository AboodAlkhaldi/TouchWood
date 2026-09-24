<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Modules\Platform\Application\Query\ListMedia\ListMedia;
use Modules\Platform\Application\Query\ListMedia\ListMediaHandler;
use Modules\Platform\Application\Query\ListMedia\MediaRow;
use Modules\Platform\Application\Query\MediaReader;
use Modules\Platform\Public\Enums\ImageFormat;
use Modules\Platform\Public\Enums\MediaSize;
use Modules\Platform\Public\Enums\MediaVariantsStatus;

/**
 * Platform's media reads, in the shape the screen wants (frontend.md 3.5, E5).
 *
 * It decides nothing: who may open the library, and what may be done to a file, are the handler's
 * answers. What happens here is a size written the way a person reads it, and a thumbnail for the
 * images that have one.
 */
final readonly class MediaPages
{
    public function __construct(
        private MediaReader $reader,
    ) {}

    /** E5. */
    public function list(ListMediaHandler $handler, ?string $cursorCreatedAt, ?string $cursorId): MediaPage
    {
        $page = $handler->handle(new ListMedia($cursorCreatedAt, $cursorId));

        return new MediaPage(
            array_map($this->row(...), $page->media),
            $page->nextCreatedAt,
            $page->nextId,
            $page->mayUpload,
            $page->mayUpdate,
            $page->mayDelete,
        );
    }

    private function row(MediaRow $media): MediaFileRow
    {
        return new MediaFileRow(
            $media->id,
            $media->originalFilename,
            $media->mime,
            $media->bytes,
            self::size($media->bytes),
            $media->width,
            $media->height,
            $media->visibility->value,
            $media->variantsStatus?->value,
            $media->retryable,
            $media->uploadedAt,
            $media->altAr,
            $media->altEn,
            $this->thumbnail($media),
            $media->usedIn,
            $media->deleteBlocked,
        );
    }

    /**
     * A thumbnail, and only for an image whose variants are ready.
     *
     * Asked for one that is still pending, Platform would have nothing to give - the variant does
     * not exist yet - and a broken picture in a grid says less than no picture at all.
     */
    private function thumbnail(MediaRow $media): ?string
    {
        if ($media->variantsStatus !== MediaVariantsStatus::Ready) {
            return null;
        }

        $thumb = $this->reader->urls($media->id)?->variants[MediaSize::Thumb->slug()] ?? null;

        if (! is_array($thumb)) {
            return null;
        }

        // Best first: a browser that cannot draw one of these is a browser we do not have.
        foreach ([ImageFormat::Avif, ImageFormat::Webp, ImageFormat::Jpeg] as $format) {
            $url = $thumb[$format->extension()] ?? null;

            if (is_string($url)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Bytes as a person reads them.
     *
     * Kilobytes of 1024, because that is what an operating system shows next to the same file, and
     * a library that disagrees with the desktop it was dragged from is just confusing.
     */
    public static function size(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        // One decimal place below ten, none above: "9.4 MB", "24 MB".
        return ($value < 10 ? number_format($value, 1) : number_format($value)).' '.$units[$unit];
    }
}
