<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Application\Media\MediaStorage;
use Modules\Platform\Application\Query\ListMedia\ListMedia;
use Modules\Platform\Application\Query\MediaReader;
use Modules\Platform\Domain\Model\Media;
use Modules\Platform\Domain\Repository\MediaRepository;
use Modules\Platform\Public\Dto\MediaDto;
use Modules\Platform\Public\Dto\MediaUrlsDto;
use Modules\Platform\Public\Enums\ImageFormat;
use Modules\Platform\Public\Enums\MediaSize;
use Modules\Platform\Public\Enums\MediaVariantsStatus;
use Modules\Platform\Public\Enums\MediaVisibility;
use stdClass;

/**
 * Reads never lock: storefront pages read media inside other modules' transactions.
 */
final readonly class DatabaseMediaReader implements MediaReader
{
    /**
     * @param  int  $privateLinkMinutes  how long a private link works (owner's decision: 30)
     */
    public function __construct(
        private MediaRepository $media,
        private MediaStorage $storage,
        private int $privateLinkMinutes,
        private ConnectionInterface $db,
    ) {}

    /**
     * One page of the library, over the table rather than through the repository: a listing loads
     * rows, not domain models, and building twenty-four Media objects to read twelve columns off
     * each of them is work nobody asked for (docs/STRUCTURE.md).
     *
     * @return list<array<string, mixed>>
     */
    public function page(ListMedia $query): array
    {
        $rows = $this->db->table('platform.media')
            ->select([
                'id', 'original_filename', 'mime', 'bytes', 'width', 'height', 'visibility',
                'variants_status', 'variants_queued_at', 'alt_ar', 'alt_en', 'created_at',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($query->perPage);

        if ($query->cursorCreatedAt !== null && $query->cursorId !== null) {
            // Strictly older than the last file shown, by the order media_created_idx keeps.
            $rows->whereRaw('(created_at, id) < (?, ?)', [$query->cursorCreatedAt, $query->cursorId]);
        }

        return array_values(array_map(static fn (stdClass $row): array => [
            'id' => (string) $row->id,
            'original_filename' => (string) $row->original_filename,
            'mime' => (string) $row->mime,
            'bytes' => (int) $row->bytes,
            'width' => $row->width === null ? null : (int) $row->width,
            'height' => $row->height === null ? null : (int) $row->height,
            'visibility' => (string) $row->visibility,
            'variants_status' => $row->variants_status === null ? null : (string) $row->variants_status,
            'variants_queued_at' => $row->variants_queued_at === null ? null : (string) $row->variants_queued_at,
            'alt_ar' => $row->alt_ar === null ? null : (string) $row->alt_ar,
            'alt_en' => $row->alt_en === null ? null : (string) $row->alt_en,
            'created_at' => (string) $row->created_at,
        ], $rows->get()->all()));
    }

    public function media(string $mediaId): ?MediaDto
    {
        $media = $this->media->byId($mediaId);

        return $media === null ? null : new MediaDto(
            $media->id(),
            $media->visibility(),
            $media->mime(),
            $media->bytes(),
            $media->width(),
            $media->height(),
            $media->originalFilename(),
            $media->altAr(),
            $media->altEn(),
            $media->variantsStatus(),
        );
    }

    public function urls(string $mediaId): ?MediaUrlsDto
    {
        $media = $this->media->byId($mediaId);

        if ($media === null) {
            return null;
        }

        // Private files never go through the CDN: only an expiring link, and no variants.
        if ($media->visibility() === MediaVisibility::Private) {
            $expiresAt = CarbonImmutable::now()->addMinutes($this->privateLinkMinutes);

            return new MediaUrlsDto($this->storage->temporaryOriginalUrl($media, $expiresAt), [], $expiresAt);
        }

        // A public image is shown only through its variants: the original keeps its metadata.
        return new MediaUrlsDto(null, $this->variantUrls($media), null);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function variantUrls(Media $media): array
    {
        if ($media->variantsStatus() !== MediaVariantsStatus::Ready) {
            return [];
        }

        $urls = [];

        foreach (MediaSize::cases() as $size) {
            foreach (ImageFormat::cases() as $format) {
                $urls[$size->slug()][$format->extension()] = $this->storage->variantUrl($media, $size, $format);
            }
        }

        return $urls;
    }
}
