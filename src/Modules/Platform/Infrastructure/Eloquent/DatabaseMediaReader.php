<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Modules\Platform\Application\Media\MediaStorage;
use Modules\Platform\Application\Query\MediaReader;
use Modules\Platform\Domain\Model\Media;
use Modules\Platform\Domain\Repository\MediaRepository;
use Modules\Platform\Public\Dto\MediaDto;
use Modules\Platform\Public\Dto\MediaUrlsDto;
use Modules\Platform\Public\Enums\ImageFormat;
use Modules\Platform\Public\Enums\MediaSize;
use Modules\Platform\Public\Enums\MediaVariantsStatus;
use Modules\Platform\Public\Enums\MediaVisibility;

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
    ) {}

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
