<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\External;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\AvifEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Modules\Platform\Application\Media\ImageVariantGenerator;
use Modules\Platform\Public\Enums\ImageFormat;
use Modules\Platform\Public\Enums\MediaSize;

/**
 * Resizes with Intervention Image on GD. Each size keeps the whole image and its shape, limits
 * only the longest side, and never enlarges a smaller original. Metadata is stripped; photos are
 * rotated by their EXIF orientation first; transparency becomes white in JPEG.
 *
 * Memory: the decoded bitmap is the expensive part (4 bytes a pixel), so only one copy is ever
 * held. The sizes are made from largest to smallest, each shrinking the previous one in place.
 */
final class InterventionImageVariantGenerator implements ImageVariantGenerator
{
    private const int AVIF_QUALITY = 60;

    private const int WEBP_QUALITY = 80;

    private const int JPEG_QUALITY = 82;

    public function variants(string $original): iterable
    {
        $manager = new ImageManager(new Driver, autoOrientation: true, decodeAnimation: false, backgroundColor: 'ffffff', strip: true);
        $image = $manager->decodeBinary($original);
        unset($original);

        $sizes = MediaSize::cases();
        usort($sizes, fn (MediaSize $a, MediaSize $b): int => $b->longestEdge() <=> $a->longestEdge());

        foreach ($sizes as $size) {
            $image->scaleDown($size->longestEdge(), $size->longestEdge());

            foreach (ImageFormat::cases() as $format) {
                $encoder = match ($format) {
                    ImageFormat::Avif => new AvifEncoder(quality: self::AVIF_QUALITY),
                    ImageFormat::Webp => new WebpEncoder(quality: self::WEBP_QUALITY),
                    ImageFormat::Jpeg => new JpegEncoder(quality: self::JPEG_QUALITY, progressive: true),
                };

                yield [$size, $format, $image->encode($encoder)->toString()];
            }
        }
    }
}
