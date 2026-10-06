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
use Modules\Platform\Public\Enums\MediaVisibility;

/**
 * Platform's media reads, in the shape the screen wants (frontend.md 3.5, E5).
 *
 * It decides nothing: who may open the library, and what may be done to a file, are the handler's
 * answers. What happens here is a thumbnail for the images that have one. A file's size goes as its
 * bytes: the screen writes it in its own language, in Latin digits (the owner's fix list, 2026-10-04).
 *
 * A private file's row carries its name, its upload date and where it is used (B2B step 3,
 * amendment 6(b)), and what describing or deleting it needs — its two descriptions, and whether a
 * use blocks the delete (amendment 8(a)). Nothing about the file itself: no type, size, picture or
 * sizes status. Only someone who may see private files is sent the row at all.
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
            $page->mayUploadPrivate,
        );
    }

    private function row(MediaRow $media): MediaFileRow
    {
        if ($media->visibility === MediaVisibility::Private) {
            return new MediaFileRow(
                $media->id,
                $media->originalFilename,
                null,
                null,
                null,
                null,
                $media->visibility->value,
                null,
                null,
                $media->uploadedAt,
                $media->altAr,
                $media->altEn,
                null,
                $media->usedIn,
                $media->deleteBlocked,
            );
        }

        return new MediaFileRow(
            $media->id,
            $media->originalFilename,
            $media->mime,
            $media->bytes,
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
}
