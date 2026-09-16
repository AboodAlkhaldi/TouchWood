<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Media;

use Modules\Platform\Public\Enums\ImageFormat;
use Modules\Platform\Public\Enums\MediaSize;

interface ImageVariantGenerator
{
    /**
     * Every size in every format, from the original image's bytes.
     *
     * @return iterable<array{MediaSize, ImageFormat, string}>
     */
    public function variants(string $original): iterable;
}
