<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Enums;

/**
 * Every size is generated in each format; browsers take AVIF, then WebP, with JPEG as fallback.
 */
enum ImageFormat: string
{
    case Avif = 'AVIF';
    case Webp = 'WEBP';
    case Jpeg = 'JPEG';

    public function extension(): string
    {
        return match ($this) {
            self::Avif => 'avif',
            self::Webp => 'webp',
            self::Jpeg => 'jpg',
        };
    }

    public function mime(): string
    {
        return match ($this) {
            self::Avif => 'image/avif',
            self::Webp => 'image/webp',
            self::Jpeg => 'image/jpeg',
        };
    }
}
