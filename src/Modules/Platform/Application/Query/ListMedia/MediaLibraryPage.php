<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListMedia;

/**
 * One page of the media library, newest first (frontend.md 3.5, E5).
 */
final readonly class MediaLibraryPage
{
    /**
     * @param  list<MediaRow>  $media
     */
    public function __construct(
        public array $media,
        public ?string $nextCreatedAt,
        public ?string $nextId,
        public bool $mayUpload,
        public bool $mayUpdate,
        public bool $mayDelete,
    ) {}
}
