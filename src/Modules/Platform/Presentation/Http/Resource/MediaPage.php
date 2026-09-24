<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * E5 - the media library (frontend.md 3.5).
 *
 * The design's table, with a switch to a grid of thumbnails [DECIDED 2026-09-19]. Paged by keyset,
 * newest first: the cursor is the last file on this page.
 */
#[TypeScript]
final class MediaPage extends Data
{
    /**
     * @param  list<MediaFileRow>  $media
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
