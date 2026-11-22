<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Media;

/**
 * What the bytes of an upload really are, read from the file itself.
 */
final readonly class InspectedFile
{
    /**
     * @param  int|null  $width  as displayed: a photo taken sideways reports its upright size
     * @param  bool  $animated  an animated WebP
     */
    public function __construct(
        public string $mime,
        public int $bytes,
        public ?int $width,
        public ?int $height,
        public bool $animated,
        public string $checksum,
    ) {}
}
