<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\External;

use finfo;
use Modules\Platform\Application\Media\InspectedFile;
use Modules\Platform\Application\Media\MediaInspector;
use RuntimeException;

/**
 * Reads only file headers: nothing here decodes the image, so an oversized one is refused before
 * it can exhaust memory.
 */
final class FinfoMediaInspector implements MediaInspector
{
    /** EXIF orientations 5–8 turn the picture a quarter: its displayed width is its stored height. */
    private const array QUARTER_TURN_ORIENTATIONS = [5, 6, 7, 8];

    /** The animation flag in a WebP file's VP8X header. */
    private const int WEBP_ANIMATION_FLAG = 0x02;

    public function inspect(string $path): InspectedFile
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("The uploaded file \"{$path}\" cannot be read.");
        }

        // From the bytes themselves: a PDF renamed .jpg is still application/pdf.
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $size = str_starts_with($mime, 'image/') ? @getimagesize($path) : false;
        [$width, $height] = $size === false ? [null, null] : [$size[0], $size[1]];

        if ($mime === 'image/jpeg' && $size !== false && in_array(self::orientation($path), self::QUARTER_TURN_ORIENTATIONS, true)) {
            [$width, $height] = [$height, $width];
        }

        return new InspectedFile(
            $mime,
            (int) filesize($path),
            $width,
            $height,
            $mime === 'image/webp' && self::isAnimatedWebp($path),
            (string) hash_file('sha256', $path),
        );
    }

    private static function orientation(string $path): int
    {
        $exif = @exif_read_data($path);

        return is_array($exif) && is_int($exif['Orientation'] ?? null) ? $exif['Orientation'] : 1;
    }

    /**
     * RIFF header (12 bytes), then a "VP8X" chunk whose first payload byte holds the flags.
     */
    private static function isAnimatedWebp(string $path): bool
    {
        $header = (string) file_get_contents($path, length: 21);

        return strlen($header) === 21
            && substr($header, 12, 4) === 'VP8X'
            && (ord($header[20]) & self::WEBP_ANIMATION_FLAG) !== 0;
    }
}
