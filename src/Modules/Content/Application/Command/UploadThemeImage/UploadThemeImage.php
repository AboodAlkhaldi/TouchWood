<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\UploadThemeImage;

/**
 * A file put into one of a theme's upload boxes (content.md §1.8, W5b), named in one of the stores the theme may pick
 * (`storeId`, the form's first store), before the theme is saved.
 */
final readonly class UploadThemeImage
{
    public function __construct(
        public ?string $storeId,
        public string $path,
        public string $fileName,
    ) {}
}
