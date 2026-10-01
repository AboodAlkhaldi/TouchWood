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
     * @param  bool  $mayUploadPrivate  whether "private" is offered when uploading here: the upload
     *                                  permission and the private-files one (amendment 6)
     */
    public function __construct(
        public array $media,
        public ?string $nextCreatedAt,
        public ?string $nextId,
        public bool $mayUpload,
        public bool $mayUpdate,
        public bool $mayDelete,
        public bool $mayUploadPrivate,
    ) {}
}
